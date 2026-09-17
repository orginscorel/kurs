<?php

namespace App\Http\Controllers\Api\Staff;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Document;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherLeave;
use App\Services\Staff\TeacherService;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherController extends ApiController
{
    public const DISK = 'local';

    public function __construct(private readonly TeacherService $teachers) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $this->applySort($query, $request, [
            'full_name' => DB::raw("CONCAT(first_name,' ',last_name)"), 'hired_on' => 'hired_on', 'created_at' => 'created_at',
        ], 'full_name');

        $weeklyHours = $this->weeklyHoursByTeacher();
        $extra = ['lessons' => $this->weeklyLessonsByTeacher(), 'classes' => $this->classCountsByTeacher()];
        $counts = $this->baseFiltered($request, ignoreStatus: true)->selectRaw('is_active, COUNT(*) AS c')->groupBy('is_active')->pluck('c', 'is_active');

        return $this->paginated($query->paginate($this->perPage($request)), fn (Teacher $t) => $this->row($t, $weeklyHours, $extra), [
            'active_count' => (int) ($counts[1] ?? 0), 'inactive_count' => (int) ($counts[0] ?? 0),
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'subjects' => Subject::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color']),
            'employment_types' => ['full_time' => 'Tam zamanlı', 'part_time' => 'Yarı zamanlı', 'hourly' => 'Saatlik'],
            'colors' => ['indigo', 'sky', 'emerald', 'amber', 'rose', 'violet', 'orange', 'slate'],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderBy(DB::raw("CONCAT(first_name,' ',last_name)"));
        Audit::log('teacher.exported', 'öğretmen listesini Excel olarak dışa aktardı.');
        $weeklyHours = $this->weeklyHoursByTeacher();

        return response()->streamDownload(function () use ($query, $weeklyHours) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['Ad Soyad', 'Unvan', 'Branşlar', 'Telefon', 'E-posta', 'Çalışma Türü', 'Bu Hafta Saat', 'Durum']));

            $query->with('subjects:id,name')->chunk(300, function ($chunk) use ($writer, $weeklyHours) {
                foreach ($chunk as $t) {
                    $writer->addRow(Row::fromValues([
                        $t->full_name, $t->title ?? '', $t->subjects->pluck('name')->join(', '), $t->phone ?? '', $t->email ?? '',
                        Teacher::EMPLOYMENT_TYPES[$t->employment_type] ?? $t->employment_type, (float) ($weeklyHours[$t->id] ?? 0),
                        $t->is_active ? 'Aktif' : 'Pasif',
                    ]));
                }
            });
            $writer->close();
        }, 'ogretmenler-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function show(Teacher $teacher): JsonResponse
    {
        $teacher->load(['subjects:id,name,color', 'user:id,username,is_active,must_change_password']);
        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->endOfWeek()->toDateString();

        $weekSchedule = DB::table('lesson_sessions as ls')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->join('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->where('ls.teacher_id', $teacher->id)->whereBetween('ls.date', [$weekStart, $weekEnd])
            ->orderBy('ls.starts_at')
            ->get(['ls.id', 'ls.date', 'ls.starts_at', 'ls.ends_at', 'ls.status', 's.name as subject', 'cg.name as class_group', 'c.name as classroom']);

        $classGroupIds = DB::table('lesson_schedules')->where('teacher_id', $teacher->id)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->distinct()->pluck('class_group_id');

        $classes = DB::table('class_groups as cg')->whereIn('cg.id', $classGroupIds)
            ->leftJoin('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'cg.id')->whereNull('cgs.left_on'))
            ->groupBy('cg.id', 'cg.name')
            ->get(['cg.id', 'cg.name', DB::raw('COUNT(cgs.id) as student_count')]);

        $termStart = now()->startOfMonth()->toDateString();
        $hoursThisMonth = (float) DB::table('lesson_sessions')->where('teacher_id', $teacher->id)->where('status', 'completed')
            ->where('date', '>=', $termStart)->selectRaw('SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) / 60 as h')->value('h') ?? 0;

        $attendanceStats = DB::table('lesson_sessions')->where('teacher_id', $teacher->id)->where('status', 'completed')
            ->where('date', '>=', now()->subDays(60)->toDateString())
            ->selectRaw('COUNT(*) as total, SUM(attendance_taken_at IS NOT NULL) as taken')->first();
        $attendanceRate = $attendanceStats && $attendanceStats->total > 0 ? round($attendanceStats->taken / $attendanceStats->total * 100) : null;

        $leaves = TeacherLeave::query()->where('teacher_id', $teacher->id)->orderByDesc('starts_on')->get();
        $availabilities = DB::table('teacher_availabilities')->where('teacher_id', $teacher->id)->orderBy('weekday')->orderBy('starts_at')->get();

        $documents = Document::query()->where('documentable_type', 'teacher')->where('documentable_id', $teacher->id)
            ->orderByDesc('created_at')->get(['id', 'title', 'category', 'mime_type', 'size', 'created_at']);

        $examTrend = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')
            ->whereIn('r.class_group_id', $classGroupIds)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->groupBy('e.id', 'e.name', 'e.exam_date')->orderByDesc('e.exam_date')->limit(6)
            ->get(['e.id', 'e.name', 'e.exam_date', DB::raw('AVG(r.net) as avg_net')])->reverse()->values();

        $homeworkStats = DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->where('h.teacher_id', $teacher->id)->whereNull('h.deleted_at')
            ->selectRaw("COUNT(*) as total, SUM(hs.status IN ('submitted','late')) as submitted, SUM(hs.score IS NOT NULL) as graded")->first();

        return response()->json([
            'teacher' => $this->row($teacher, [$teacher->id => $this->weeklyHoursFor($teacher->id)]) + [
                'first_name' => $teacher->first_name, 'last_name' => $teacher->last_name, 'hired_on' => $teacher->hired_on?->toDateString(),
                'employment_type' => $teacher->employment_type, 'hourly_rate' => $teacher->hourly_rate, 'max_weekly_hours' => $teacher->max_weekly_hours,
                'whatsapp_phone' => $teacher->whatsapp_phone, 'notes' => $teacher->notes, 'subject_ids' => $teacher->subjects->pluck('id'),
                'user' => $teacher->user ? ['id' => $teacher->user->id, 'username' => $teacher->user->username, 'is_active' => $teacher->user->is_active, 'must_change_password' => $teacher->user->must_change_password] : null,
            ],
            'week_schedule' => $weekSchedule,
            'classes' => $classes,
            'hours_this_month' => round($hoursThisMonth, 1),
            'attendance_rate' => $attendanceRate,
            'leaves' => $leaves,
            'availabilities' => $availabilities,
            'documents' => $documents,
            'performance' => [
                'exam_trend' => $examTrend,
                'homework_total' => (int) ($homeworkStats->total ?? 0),
                'homework_submitted' => (int) ($homeworkStats->submitted ?? 0),
                'homework_graded' => (int) ($homeworkStats->graded ?? 0),
                'grading_rate' => $homeworkStats && $homeworkStats->submitted > 0 ? round($homeworkStats->graded / $homeworkStats->submitted * 100) : null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $result = $this->teachers->create($data);

        return response()->json([
            'teacher' => $result['teacher'],
            'temp_password' => $result['temp_password'],
        ], 201);
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $data = $this->validated($request, $teacher);
        $result = $this->teachers->update($teacher, $data);

        return response()->json(['teacher' => $result['teacher'], 'temp_password' => $result['temp_password']]);
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $this->teachers->delete($teacher);

        return $this->ok('Öğretmen kaydı silindi.');
    }

    public function uploadPhoto(Request $request, Teacher $teacher): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'max:4096']]);
        $path = $request->file('photo')->store('teachers/avatars', 'public');
        $teacher->forceFill(['avatar_path' => $path])->save();
        Audit::log('teacher.photo_updated', "{$teacher->full_name} fotoğrafını güncelledi.", $teacher);

        return response()->json(['avatar_url' => Storage::disk('public')->url($path)]);
    }

    // --- İzinler ---

    public function leavesStore(Request $request, Teacher $teacher): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'kind' => ['required', Rule::in(['annual', 'sick', 'excuse', 'other'])],
            'reason' => ['nullable', 'string', 'max:500'], 'status' => ['sometimes', Rule::in(['requested', 'approved', 'rejected'])],
        ]);
        $data['status'] ??= 'approved';
        $leave = $teacher->leaves()->create($data);
        Audit::log('teacher.leave_created', "{$teacher->full_name} için {$data['starts_on']} - {$data['ends_on']} izin kaydı oluşturdu.", $teacher);

        return response()->json($leave, 201);
    }

    public function leavesUpdate(Request $request, Teacher $teacher, TeacherLeave $leave): JsonResponse
    {
        abort_unless($leave->teacher_id === $teacher->id, 404);
        $data = $request->validate([
            'starts_on' => ['sometimes', 'date'], 'ends_on' => ['sometimes', 'date', 'after_or_equal:starts_on'],
            'kind' => ['sometimes', Rule::in(['annual', 'sick', 'excuse', 'other'])],
            'reason' => ['nullable', 'string', 'max:500'], 'status' => ['sometimes', Rule::in(['requested', 'approved', 'rejected'])],
        ]);
        $leave->update($data);
        Audit::log('teacher.leave_updated', "{$teacher->full_name} izin kaydını güncelledi.", $teacher);

        return response()->json($leave);
    }

    public function leavesDestroy(Teacher $teacher, TeacherLeave $leave): JsonResponse
    {
        abort_unless($leave->teacher_id === $teacher->id, 404);
        $leave->delete();
        Audit::log('teacher.leave_deleted', "{$teacher->full_name} izin kaydını sildi.", $teacher);

        return $this->ok('İzin kaydı silindi.');
    }

    // --- Belgeler ---

    public function documentsStore(Request $request, Teacher $teacher): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240'], 'category' => ['required', 'string', 'max:40']]);
        $file = $request->file('file');
        $path = $file->store('teachers/'.$teacher->id.'/documents', self::DISK);
        if (! $path) {
            throw new BusinessRuleException('Dosya kaydedilemedi.', 'upload_failed', [], 500);
        }
        $document = Document::query()->create([
            'documentable_type' => 'teacher', 'documentable_id' => $teacher->id, 'category' => $request->string('category'),
            'title' => mb_substr($file->getClientOriginalName(), 0, 190), 'disk' => self::DISK, 'path' => $path,
            'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'visibility' => 'staff', 'uploaded_by' => $request->user()->id,
        ]);
        Audit::log('teacher.document_uploaded', "{$teacher->full_name} için belge yükledi: {$document->title}.", $teacher);

        return response()->json($document, 201);
    }

    public function documentsDownload(Teacher $teacher, Document $document): StreamedResponse
    {
        abort_unless($document->documentable_type === 'teacher' && $document->documentable_id === $teacher->id, 404);

        return Storage::disk($document->disk)->download($document->path, $document->title);
    }

    public function documentsDestroy(Teacher $teacher, Document $document): JsonResponse
    {
        abort_unless($document->documentable_type === 'teacher' && $document->documentable_id === $teacher->id, 404);
        Storage::disk($document->disk)->delete($document->path);
        $document->delete();
        Audit::log('teacher.document_deleted', "{$teacher->full_name} için belge sildi: {$document->title}.", $teacher);

        return $this->ok('Belge silindi.');
    }

    /** @param array{lessons?: array<int,int>, classes?: array<int,int>} $extra  liste için toplu hesaplanan bu hafta ders sayısı ve sınıf sayısı */
    private function row(Teacher $teacher, array $weeklyHours, array $extra = []): array
    {
        return [
            'id' => $teacher->id, 'full_name' => $teacher->full_name, 'title' => $teacher->title, 'specialty' => $teacher->specialty,
            'phone' => $teacher->phone, 'email' => $teacher->email, 'color' => $teacher->color, 'avatar_url' => $teacher->avatar_path ? Storage::disk('public')->url($teacher->avatar_path) : null,
            'employment_type' => $teacher->employment_type, 'employment_type_label' => Teacher::EMPLOYMENT_TYPES[$teacher->employment_type] ?? $teacher->employment_type,
            'is_active' => $teacher->is_active, 'has_user' => (bool) $teacher->user_id,
            'subjects' => $teacher->relationLoaded('subjects') ? $teacher->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color]) : [],
            'weekly_hours' => (float) ($weeklyHours[$teacher->id] ?? 0),
            'target_weekly_hours' => $teacher->target_weekly_hours !== null ? (int) $teacher->target_weekly_hours : null,
            'hired_on' => $teacher->hired_on?->toDateString(),
            'week_lessons' => isset($extra['lessons']) ? (int) ($extra['lessons'][$teacher->id] ?? 0) : null,
            'class_count' => isset($extra['classes']) ? (int) ($extra['classes'][$teacher->id] ?? 0) : null,
        ];
    }

    private function filtered(Request $request, bool $ignoreStatus = false): Builder
    {
        return $this->baseFiltered($request, $ignoreStatus)->with('subjects:id,name,color')->withCount('subjects');
    }

    private function baseFiltered(Request $request, bool $ignoreStatus = false): Builder
    {
        $query = Teacher::query();

        if ($q = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like])->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like));
        }
        if ($subjectId = $request->query('subject_id')) {
            $query->whereHas('subjects', fn ($w) => $w->where('subjects.id', $subjectId));
        }
        if ($type = $request->query('employment_type')) {
            $query->where('employment_type', $type);
        }
        if (! $ignoreStatus && $request->filled('status')) {
            $query->where('is_active', $request->query('status') === 'active');
        }

        return $query;
    }

    private function weeklyHoursByTeacher(): array
    {
        return DB::table('lesson_sessions')
            ->whereBetween('date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('teacher_id, SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) / 60 as hours')
            ->groupBy('teacher_id')->pluck('hours', 'teacher_id')->all();
    }

    /** Bu haftaki (iptal hariç) ders oturumu sayısı, öğretmen bazında tek sorgu */
    private function weeklyLessonsByTeacher(): array
    {
        return DB::table('lesson_sessions')
            ->whereBetween('date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('teacher_id, COUNT(*) as c')
            ->groupBy('teacher_id')->pluck('c', 'teacher_id')->all();
    }

    /** Geçerli haftalık programda ders verdiği farklı sınıf sayısı, öğretmen bazında tek sorgu */
    private function classCountsByTeacher(): array
    {
        return DB::table('lesson_schedules')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->selectRaw('teacher_id, COUNT(DISTINCT class_group_id) as c')
            ->groupBy('teacher_id')->pluck('c', 'teacher_id')->all();
    }

    private function weeklyHoursFor(int $teacherId): float
    {
        return (float) (DB::table('lesson_sessions')->where('teacher_id', $teacherId)
            ->whereBetween('date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) / 60 as h')->value('h') ?? 0);
    }

    private function validated(Request $request, ?Teacher $teacher = null): array
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:60'], 'specialty' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'], 'whatsapp_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'], 'color' => ['nullable', 'string', 'max:20'],
            'hired_on' => ['nullable', 'date'], 'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'hourly'])],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'], 'max_weekly_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'is_active' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
            'subject_ids' => ['sometimes', 'array'], 'subject_ids.*' => ['integer', 'exists:subjects,id'],
            'create_user' => ['sometimes', 'boolean'], 'username' => ['nullable', 'string', 'max:60'],
        ]);

        return $data;
    }
}
