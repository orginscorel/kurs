<?php

namespace App\Http\Controllers\Api\TeacherPortal;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\Document;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\LessonSession;
use App\Models\Teacher;
use App\Services\Academic\HomeworkService;
use App\Services\Attendance\AttendanceTakingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Öğretmen portalı — yoklama ve ödev. Yalnız öğretmenin KENDİ dersleri/ödevleri:
 * başka öğretmenin dersi/ödevi → 404 (varlığı sızdırılmaz); kapsam dışı sınıf/öğrenci → 403.
 */
class TeacherWorkController extends TeacherPortalController
{
    public function __construct(private readonly HomeworkService $homeworkService, private readonly AttendanceTakingService $taking) {}

    // ------------------------------------------------------------------ yoklama

    /** Seçilen günün dersleri + son 7 günde yoklaması eksik dersler. */
    public function attendanceIndex(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $day = $this->parseDay($request->query('date')) ?? CarbonImmutable::today();

        return $this->isoJson([
            'date' => $day->toDateString(),
            'data' => $this->sessions($teacher, $day, $day)->values(),
            'pending' => $this->pendingAttendance($teacher),
            'edit_days' => self::ATTENDANCE_EDIT_DAYS,
        ]);
    }

    public function attendanceShow(Request $request, int $session): JsonResponse
    {
        $s = $this->ownSession($request, $session);
        $data = $this->taking->roster($s);

        $data['students'] = collect($data['students'])->map(fn ($r) => [
            ...$r,
            'photo_url' => $r['photo_path'] ? Storage::disk('public')->url($r['photo_path']) : null,
        ])->map(fn ($r) => collect($r)->except('photo_path')->all())->values();
        $data['session']['status'] = $s->status;
        $data['can_edit'] = self::attendanceWindowOpen($s->status, $s->date->toDateString(), (string) $s->starts_at) && $request->user()->can('teacher_portal.attendance');
        $data['edit_reason'] = $data['can_edit'] ? null : $this->closedReason($s);
        $data['statuses'] = self::ATTENDANCE_LABELS;

        return $this->isoJson($data);
    }

    public function attendanceStore(Request $request, int $session): JsonResponse
    {
        $s = $this->ownSession($request, $session);
        if (! self::attendanceWindowOpen($s->status, $s->date->toDateString(), (string) $s->starts_at)) {
            throw new BusinessRuleException($this->closedReason($s), 'attendance_window_closed', [], 422);
        }

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.student_id' => ['required', 'integer'],
            'rows.*.status' => ['required', Rule::in(array_keys(Attendance::STATUSES))],
            'rows.*.late_minutes' => ['nullable', 'integer', 'min:1', 'max:300'],
            'rows.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        // Yalnız dersin sınıf listesindeki öğrenciler
        $roster = DB::table('class_group_student')->where('class_group_id', $s->class_group_id)->whereNull('left_on')->pluck('student_id')->map(fn ($v) => (int) $v);
        $foreign = collect($data['rows'])->pluck('student_id')->map(fn ($v) => (int) $v)->diff($roster);
        if ($foreign->isNotEmpty()) {
            throw new BusinessRuleException('Listede bu dersin sınıfında olmayan öğrenci var.', 'attendance_student_not_in_class', [], 422);
        }
        $rows = collect($data['rows'])->unique('student_id')->values()->all();

        $count = $this->taking->save($s, $rows, $request->user());
        $absent = collect($rows)->where('status', 'absent')->count();

        return $this->isoJson(['message' => "{$count} öğrencinin yoklaması kaydedildi".($absent ? " ({$absent} gelmedi)." : '.')]);
    }

    private function ownSession(Request $request, int $id): LessonSession
    {
        return LessonSession::query()->whereKey($id)->where('teacher_id', $this->teacher($request)->id)
            ->with(['subject:id,name', 'classGroup:id,name', 'classroom:id,name', 'teacher:id,first_name,last_name'])->firstOrFail();
    }

    private function closedReason(LessonSession $s): string
    {
        if ($s->status === 'cancelled') {
            return 'Bu ders iptal edildi; yoklama alınmaz.';
        }
        if (CarbonImmutable::parse((string) $s->starts_at)->subMinutes(15)->isFuture()) {
            return 'Yoklama dersin başlamasına 15 dakika kala açılır.';
        }

        return 'Yoklama en fazla '.self::ATTENDANCE_EDIT_DAYS.' gün geriye kadar düzeltilebilir. Daha eski kayıtlar için yönetime başvurun.';
    }

    // ------------------------------------------------------------------ ödevler

    public function homeworkIndex(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $status = (string) $request->query('status', 'open');

        $base = fn () => Homework::query()->where('teacher_id', $teacher->id);
        $rows = $base()
            ->with(['subject:id,name,color', 'classGroup:id,name'])
            ->withCount([
                'submissions as total_count',
                'submissions as done_count' => fn ($q) => $q->whereIn('status', ['submitted', 'late']),
                'submissions as missed_count' => fn ($q) => $q->where('status', 'missed'),
                'submissions as to_grade_count' => fn ($q) => $q->whereIn('status', ['submitted', 'late'])->whereNull('score')->whereNull('graded_at'),
            ])
            ->when($status === 'open', fn ($q) => $q->where('due_at', '>', now()))
            ->when($status === 'closed', fn ($q) => $q->where('due_at', '<=', now()))
            ->when($status === 'to_grade', fn ($q) => $q->whereHas('submissions', fn ($w) => $w->whereIn('status', ['submitted', 'late'])->whereNull('score')->whereNull('graded_at')))
            ->when($request->integer('class_group_id'), fn ($q, $g) => $q->where('class_group_id', $g))
            ->orderByRaw($status === 'open' ? 'due_at ASC' : 'due_at DESC')
            ->limit(100)->get()
            ->map(fn (Homework $h) => $this->homeworkRow($h));

        return $this->isoJson([
            'data' => $rows,
            'counts' => [
                'open' => $base()->where('due_at', '>', now())->count(),
                'closed' => $base()->where('due_at', '<=', now())->count(),
                'to_grade' => $base()->whereHas('submissions', fn ($w) => $w->whereIn('status', ['submitted', 'late'])->whereNull('score')->whereNull('graded_at'))->count(),
            ],
        ]);
    }

    /** Ödev formu seçenekleri: sınıflarım + her sınıfta verdiğim dersler + konular. */
    public function homeworkOptions(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $groups = $scope->groupsWithMeta();
        $subjectIds = $scope->subjectIds();

        $subjects = DB::table('subjects')->whereIn('id', $subjectIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color']);
        $topics = DB::table('topics')->whereIn('subject_id', $subjectIds)->orderBy('subject_id')->orderBy('sort')->get(['id', 'subject_id', 'name']);

        return $this->isoJson([
            'groups' => $groups->map(fn ($g) => ['id' => $g['id'], 'name' => $g['name'], 'student_count' => $g['student_count'], 'subject_ids' => collect($g['subjects'])->pluck('id')->values()]),
            'subjects' => $subjects,
            'topics' => $topics,
            'max_file_mb' => (int) (HomeworkService::MAX_FILE_KB / 1024),
            'allowed_ext' => HomeworkService::ALLOWED_EXT,
        ]);
    }

    public function homeworkStore(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $data = $this->validatedHomework($request);

        $scope = $this->scope($request);
        $scope->assertGroup((int) $data['class_group_id']);
        $this->assertSubjectTopic($request, (int) $data['subject_id'], $data['topic_id'] ?? null);

        if (! empty($data['student_ids'])) {
            $inGroup = DB::table('class_group_student')->where('class_group_id', $data['class_group_id'])->whereNull('left_on')->pluck('student_id')->map(fn ($v) => (int) $v);
            if (collect($data['student_ids'])->map(fn ($v) => (int) $v)->diff($inGroup)->isNotEmpty()) {
                throw new BusinessRuleException('Seçilen öğrencilerden bazıları bu sınıfta değil.', 'teacher_scope_student', [], 403);
            }
        }

        $data['teacher_id'] = $teacher->id;
        $hw = $this->homeworkService->create($data, $request->user());

        foreach ((array) $request->file('files', []) as $file) {
            $this->homeworkService->attach($hw, $file, $request->user());
        }

        return $this->isoJson(['message' => 'Ödev verildi.', 'id' => $hw->id], 201);
    }

    public function homeworkShow(Request $request, int $homework): JsonResponse
    {
        $hw = $this->ownHomework($request, $homework);
        $hw->load(['subject:id,name,color', 'classGroup:id,name', 'topic:id,name'])
            ->loadCount(['submissions as total_count', 'submissions as done_count' => fn ($q) => $q->whereIn('status', ['submitted', 'late']),
                'submissions as missed_count' => fn ($q) => $q->where('status', 'missed'),
                'submissions as to_grade_count' => fn ($q) => $q->whereIn('status', ['submitted', 'late'])->whereNull('score')->whereNull('graded_at')]);

        $subs = DB::table('homework_submissions as hs')->join('students as st', 'st.id', '=', 'hs.student_id')
            ->where('hs.homework_id', $hw->id)
            ->orderBy('st.first_name')->orderBy('st.last_name')
            ->get(['hs.id', 'hs.student_id', 'st.full_name', 'st.student_no', 'st.photo_path', 'hs.status', 'hs.seen_at', 'hs.submitted_at',
                'hs.answer_text', 'hs.score', 'hs.teacher_note', 'hs.graded_at']);

        $files = $subs->isEmpty() ? collect() : $this->homeworkService->submissionFiles($subs->pluck('id')->all())->get()->groupBy('documentable_id');

        return $this->isoJson([
            'homework' => [...$this->homeworkRow($hw), 'description' => $hw->description, 'topic_id' => $hw->topic_id, 'topic' => $hw->topic?->name],
            'documents' => $this->homeworkService->documents($hw)->get()->map(fn (Document $d) => $this->docRow($d)),
            'submissions' => $subs->map(fn ($s) => [
                'id' => (int) $s->id,
                'student_id' => (int) $s->student_id,
                'full_name' => $s->full_name,
                'student_no' => $s->student_no,
                'photo_url' => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null,
                'status' => $s->status,
                'status_label' => self::HOMEWORK_LABELS[$s->status] ?? $s->status,
                'seen_at' => $s->seen_at,
                'submitted_at' => $s->submitted_at,
                'answer_text' => $s->answer_text,
                'score' => $s->score,
                'teacher_note' => $s->teacher_note,
                'graded_at' => $s->graded_at,
                'files' => ($files[$s->id] ?? collect())->map(fn (Document $d) => $this->docRow($d))->values(),
            ]),
            'avg_score' => ($avg = $subs->whereNotNull('score')->avg('score')) !== null ? round((float) $avg, 1) : null,
            'statuses' => self::HOMEWORK_LABELS,
            'can_manage' => $request->user()->can('teacher_portal.homework'),
        ]);
    }

    public function homeworkUpdate(Request $request, int $homework): JsonResponse
    {
        $hw = $this->ownHomework($request, $homework);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['sometimes', 'date'],
            'topic_id' => ['nullable', 'integer'],
        ]);
        if (array_key_exists('topic_id', $data)) {
            $this->assertSubjectTopic($request, $hw->subject_id, $data['topic_id']);
        }
        if (isset($data['due_at']) && CarbonImmutable::parse($data['due_at'])->lte(CarbonImmutable::parse((string) $hw->assigned_at))) {
            throw new BusinessRuleException('Son teslim, ödevin verildiği zamandan sonra olmalı.', 'due_before_assigned', [], 422);
        }
        $this->homeworkService->update($hw, $data);

        return $this->ok('Ödev güncellendi.');
    }

    public function homeworkDestroy(Request $request, int $homework): JsonResponse
    {
        $hw = $this->ownHomework($request, $homework);
        $done = $hw->submissions()->whereIn('status', ['submitted', 'late'])->count();
        if ($done > 0) {
            throw new BusinessRuleException("Bu ödevi {$done} öğrenci teslim etti; silinemez. Gerekirse son teslim tarihini değiştirin.", 'homework_has_submissions', [], 422);
        }
        $this->homeworkService->delete($hw);

        return $this->ok('Ödev silindi.');
    }

    /** Toplu değerlendirme: durum, puan (0-100), geri bildirim. */
    public function homeworkGrade(Request $request, int $homework): JsonResponse
    {
        $hw = $this->ownHomework($request, $homework);
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.student_id' => ['required', 'integer'],
            'rows.*.status' => ['nullable', Rule::in(array_keys(HomeworkSubmission::STATUSES))],
            'rows.*.score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rows.*.teacher_note' => ['nullable', 'string', 'max:1000'],
        ], ['rows.*.score.max' => 'Puan 0-100 arasında olmalı.', 'rows.*.score.min' => 'Puan 0-100 arasında olmalı.'], ['rows.*.teacher_note' => 'Öğretmen notu', 'rows.*.status' => 'Teslim durumu']);

        $n = $this->homeworkService->grade($hw, $data['rows'], $request->user());

        return $this->ok("{$n} öğrencinin değerlendirmesi kaydedildi.", ['updated' => $n]);
    }

    public function homeworkUpload(Request $request, int $homework): JsonResponse
    {
        $hw = $this->ownHomework($request, $homework);
        $request->validate(['file' => ['required', 'file', 'max:'.HomeworkService::MAX_FILE_KB]], ['file.max' => 'Dosya en fazla 15 MB olabilir.']);
        if ($this->homeworkService->documents($hw)->count() >= 10) {
            throw new BusinessRuleException('Bir ödeve en fazla 10 dosya eklenebilir.', 'too_many_files', [], 422);
        }
        $doc = $this->homeworkService->attach($hw, $request->file('file'), $request->user());

        return $this->isoJson(['message' => 'Dosya eklendi.', 'data' => $this->docRow($doc)], 201);
    }

    public function homeworkDocumentDestroy(Request $request, int $homework, int $document): JsonResponse
    {
        $hw = $this->ownHomework($request, $homework);
        $doc = Document::query()->whereKey($document)->where('documentable_type', 'homework')->where('documentable_id', $hw->id)->firstOrFail();
        $this->homeworkService->detach($hw, $doc);

        return $this->ok('Dosya silindi.');
    }

    /** Ödev dosyası ya da bu ödeve ait öğrenci teslim dosyası. */
    public function homeworkDocument(Request $request, int $homework, int $document): StreamedResponse
    {
        $hw = $this->ownHomework($request, $homework);
        $subIds = $hw->submissions()->pluck('id');
        $doc = Document::query()->whereKey($document)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('documentable_type', 'homework')->where('documentable_id', $hw->id))
                ->orWhere(fn ($w) => $w->where('documentable_type', 'homework_submission')->whereIn('documentable_id', $subIds)))
            ->firstOrFail();

        if (! Storage::disk($doc->disk)->exists($doc->path)) {
            throw new BusinessRuleException('Dosya sunucuda bulunamadı.', 'file_missing', [], 404);
        }

        return Storage::disk($doc->disk)->download($doc->path, $doc->title);
    }

    private function ownHomework(Request $request, int $id): Homework
    {
        return Homework::query()->whereKey($id)->where('teacher_id', $this->teacher($request)->id)->firstOrFail();
    }

    private function assertSubjectTopic(Request $request, int $subjectId, mixed $topicId): void
    {
        if (! $this->scope($request)->subjectIds()->contains($subjectId)) {
            throw new BusinessRuleException('Bu ders sizin derslerinizden değil.', 'teacher_scope_subject', [], 422);
        }
        if ($topicId && ! DB::table('topics')->where('id', $topicId)->where('subject_id', $subjectId)->exists()) {
            throw new BusinessRuleException('Seçilen konu bu derse ait değil.', 'topic_mismatch', [], 422);
        }
    }

    private function validatedHomework(Request $request): array
    {
        return $request->validate([
            'class_group_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'topic_id' => ['nullable', 'integer'],
            'student_ids' => ['nullable', 'array', 'max:200'],
            'student_ids.*' => ['integer'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['required', 'date'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:'.HomeworkService::MAX_FILE_KB],
        ], [
            'class_group_id.required' => 'Sınıf seçin.',
            'subject_id.required' => 'Ders seçin.',
            'title.required' => 'Ödev başlığını yazın.',
            'due_at.required' => 'Son teslim tarihini seçin.',
            'files.*.max' => 'Dosya en fazla 15 MB olabilir.',
        ]);
    }

    private function homeworkRow(Homework $h): array
    {
        $total = (int) ($h->total_count ?? 0);

        return [
            'id' => $h->id,
            'title' => $h->title,
            'assigned_at' => $h->assigned_at?->toAtomString(),
            'due_at' => $h->due_at->toAtomString(),
            'is_open' => $h->due_at->isFuture(),
            'subject_id' => $h->subject_id,
            'subject' => $h->subject?->name,
            'subject_color' => $h->subject?->color,
            'class_group_id' => $h->class_group_id,
            'class_group' => $h->classGroup?->name,
            'total_count' => $total,
            'done_count' => (int) ($h->done_count ?? 0),
            'missed_count' => (int) ($h->missed_count ?? 0),
            'to_grade_count' => (int) ($h->to_grade_count ?? 0),
            'completion' => $total ? (int) round(((int) ($h->done_count ?? 0)) / $total * 100) : 0,
        ];
    }

    private function docRow(Document $d): array
    {
        return ['id' => $d->id, 'title' => $d->title, 'mime_type' => $d->mime_type, 'size' => (int) $d->size, 'created_at' => $d->created_at?->toAtomString()];
    }
}
