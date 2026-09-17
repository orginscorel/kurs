<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Api\ApiController;
use App\Models\ClassGroup;
use App\Models\Student;
use App\Services\Academic\ClassGroupService;
use App\Services\Academic\TimeSlots;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClassGroupController extends ApiController
{
    public function __construct(private readonly ClassGroupService $groups) {}

    public function index(Request $request): JsonResponse
    {
        $query = ClassGroup::query()->with(['program:id,name,color,kind', 'term:id,name', 'homeroom:id,name', 'advisor:id,first_name,last_name'])
            ->withCount(['activeStudents as students_count', 'schedules as lessons_count'])
            ->addSelect([
                // Son 30 gün devam yüzdesi (geldi + geç geldi / tüm yoklama) ve bugünkü ders sayısı — alt sorgu, N+1 yok
                // Sınıfın güncel öğrencilerinin yoklaması (ders oturumu bağı olmayan yoklamalar da sayılsın diye üyelik üzerinden)
                'attendance_30' => DB::table('attendances as a')->join('class_group_student as cgs', 'cgs.student_id', '=', 'a.student_id')
                    ->selectRaw("ROUND(SUM(a.status IN ('present','late')) / COUNT(*) * 100)")
                    ->whereColumn('cgs.class_group_id', 'class_groups.id')->whereNull('cgs.left_on')->where('a.date', '>=', now()->subDays(30)->toDateString()),
                'today_lessons' => DB::table('lesson_sessions')->selectRaw('COUNT(*)')->whereColumn('class_group_id', 'class_groups.id')
                    ->where('date', now()->toDateString())->where('status', '!=', 'cancelled'),
            ])
            ->when($request->query('q'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->when($request->integer('program_id'), fn ($q, $id) => $q->where('program_id', $id))
            ->when($request->integer('academic_term_id'), fn ($q, $id) => $q->where('academic_term_id', $id))
            ->when($request->integer('advisor_teacher_id'), fn ($q, $id) => $q->where('advisor_teacher_id', $id))
            ->when($request->query('status', 'active') === 'active', fn ($q) => $q->where('is_active', true));
        $this->applySort($query, $request, ['name' => 'name', 'capacity' => 'capacity', 'students_count' => 'students_count', 'lessons_count' => 'lessons_count', 'program' => 'program_id'], 'name');

        return $this->paginated($query->paginate($this->perPage($request, 50)), fn (ClassGroup $g) => $this->row($g));
    }

    public function show(Request $request, ClassGroup $group): JsonResponse
    {
        $group->load(['program:id,name,color,kind', 'term:id,name,starts_on,ends_on', 'homeroom:id,name,capacity', 'advisor:id,first_name,last_name,phone'])
            ->loadCount(['activeStudents as students_count', 'schedules as lessons_count']);
        $sensitive = $request->user()->can('students.view_sensitive');
        $today = CarbonImmutable::today();

        $students = $group->activeStudents()->with(['guardians' => fn ($q) => $q->wherePivot('is_primary', true)])
            ->orderBy('students.full_name')->get()
            ->map(fn (Student $s) => [
                'id' => $s->id, 'student_no' => $s->student_no, 'full_name' => $s->full_name, 'status' => $s->status, 'status_label' => Student::STATUSES[$s->status] ?? $s->status,
                'photo_url' => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null, 'school_grade' => $s->school_grade,
                'phone' => $sensitive ? $s->phone : Sensitive::maskPhone($s->phone),
                'guardian' => $s->guardians->first() ? ['name' => $s->guardians->first()->full_name, 'phone' => $sensitive ? $s->guardians->first()->phone : Sensitive::maskPhone($s->guardians->first()->phone)] : null,
                'joined_on' => $s->pivot->joined_on,
            ]);

        $schedules = DB::table('lesson_schedules as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('teachers as t', 't.id', '=', 'ls.teacher_id')->join('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->where('ls.class_group_id', $group->id)->whereNull('ls.deleted_at')
            ->where(fn ($q) => $q->whereNull('ls.valid_until')->orWhere('ls.valid_until', '>=', $today->toDateString()))
            ->orderBy('ls.weekday')->orderBy('ls.starts_at')
            ->get(['ls.id', 'ls.weekday', 'ls.starts_at', 'ls.ends_at', 's.name as subject', 's.color as subject_color', 'c.name as classroom', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        $attendance = DB::table('attendances as a')->join('lesson_sessions as ls', 'ls.id', '=', 'a.lesson_session_id')
            ->where('ls.class_group_id', $group->id)->where('a.date', '>=', $today->subDays(30)->toDateString())
            ->selectRaw("COUNT(*) AS total, SUM(a.status='present') AS present, SUM(a.status='late') AS late, SUM(a.status='absent') AS absent, SUM(a.status IN ('excused','medical')) AS excused")->first();

        $lastExam = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->where('r.class_group_id', $group->id)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->groupBy('e.id', 'e.name', 'e.exam_date')->orderByDesc('e.exam_date')->limit(6)
            ->get(['e.id', 'e.name', 'e.exam_date', DB::raw('ROUND(AVG(r.net), 2) AS avg_net'), DB::raw('MAX(r.net) AS max_net'), DB::raw('COUNT(*) AS participants')]);

        $homework = DB::table('homework as h')->leftJoin('homework_submissions as hs', 'hs.homework_id', '=', 'h.id')->where('h.class_group_id', $group->id)->whereNull('h.deleted_at')
            ->selectRaw("COUNT(DISTINCT h.id) AS total, SUM(h.due_at > NOW()) > 0 AS has_open, SUM(hs.status IN ('submitted','late')) AS done, SUM(hs.status='missed') AS missed, COUNT(hs.id) AS assignments")->first();

        $upcoming = DB::table('lesson_sessions as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('classrooms as c', 'c.id', '=', 'ls.classroom_id')->join('teachers as t', 't.id', '=', 'ls.teacher_id')
            ->where('ls.class_group_id', $group->id)->where('ls.status', '!=', 'cancelled')->where('ls.ends_at', '>', now())->orderBy('ls.starts_at')->limit(5)
            ->get(['ls.id', 'ls.starts_at', 'ls.ends_at', 's.name as subject', 's.color as subject_color', 'c.name as classroom', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        return response()->json([
            'group' => [...$this->row($group), 'term_dates' => [$group->term?->starts_on?->toDateString(), $group->term?->ends_on?->toDateString()], 'advisor_phone' => $sensitive ? $group->advisor?->phone : Sensitive::maskPhone($group->advisor?->phone), 'homeroom_capacity' => $group->homeroom?->capacity],
            'students' => $students,
            'schedules' => $schedules->map(fn ($r) => [...(array) $r, 'weekday_label' => TimeSlots::WEEKDAYS[$r->weekday] ?? '']),
            'weekly_minutes' => (int) $schedules->sum(fn ($r) => TimeSlots::toMinutes($r->ends_at) - TimeSlots::toMinutes($r->starts_at)),
            'attendance_30' => $attendance,
            'exams' => $lastExam,
            'homework' => $homework,
            'upcoming' => $upcoming,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $group = $this->groups->create($this->validated($request));

        return response()->json(['message' => 'Sınıf oluşturuldu.', 'id' => $group->id], 201);
    }

    public function update(Request $request, ClassGroup $group): JsonResponse
    {
        $this->groups->update($group, $this->validated($request, $group));

        return $this->ok('Sınıf güncellendi.');
    }

    public function destroy(ClassGroup $group): JsonResponse
    {
        $this->groups->delete($group);

        return $this->ok('Sınıf silindi.');
    }

    public function addStudents(Request $request, ClassGroup $group): JsonResponse
    {
        $data = $request->validate(['student_ids' => ['required', 'array', 'min:1', 'max:200'], 'student_ids.*' => ['integer'], 'joined_on' => ['nullable', 'date']], [], ['student_ids' => 'Öğrenciler', 'student_ids.*' => 'Öğrenci', 'joined_on' => 'Katılma tarihi']);
        $result = $this->groups->addStudents($group, $data['student_ids'], $data['joined_on'] ?? now()->toDateString());

        return response()->json(['message' => $result['added'] ? "{$result['added']} öğrenci sınıfa eklendi." : 'Eklenen öğrenci yok.', ...$result]);
    }

    public function removeStudent(Request $request, ClassGroup $group, Student $student): JsonResponse
    {
        $this->groups->removeStudent($group, $student, $request->query('left_on'));

        return $this->ok("{$student->full_name} sınıftan çıkarıldı.");
    }

    /** Sınıfa eklenebilecek öğrenciler: bu sınıfta olmayan aktif öğrenciler (arama). */
    public function candidates(Request $request, ClassGroup $group): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $rows = Student::query()->whereIn('status', ['active', 'enrolled', 'pending'])
            ->whereNotExists(fn ($s) => $s->from('class_group_student')->whereColumn('student_id', 'students.id')->where('class_group_id', $group->id)->whereNull('left_on'))
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w->where('full_name', 'like', "%$q%")->orWhere('student_no', 'like', "$q%")))
            ->with('currentClassGroups:id,name')->orderBy('full_name')->limit(30)->get(['id', 'student_no', 'full_name', 'school_grade', 'status']);

        return response()->json(['data' => $rows->map(fn ($s) => ['id' => $s->id, 'student_no' => $s->student_no, 'full_name' => $s->full_name, 'school_grade' => $s->school_grade, 'current' => $s->currentClassGroups->pluck('name')->join(', ')])]);
    }

    private function row(ClassGroup $g): array
    {
        return [
            ...$g->only(['id', 'name', 'capacity', 'is_active', 'program_id', 'academic_term_id', 'homeroom_classroom_id', 'advisor_teacher_id']),
            'program' => $g->program?->name, 'program_color' => $g->program?->color, 'program_kind' => $g->program?->kind, 'term' => $g->term?->name,
            'homeroom' => $g->homeroom?->name, 'advisor' => $g->advisor?->full_name,
            'students_count' => (int) ($g->students_count ?? 0), 'lessons_count' => (int) ($g->lessons_count ?? 0),
            'fill_rate' => $g->capacity > 0 ? round(((int) ($g->students_count ?? 0)) / $g->capacity * 100) : 0,
            'attendance_30' => isset($g->attendance_30) ? (int) $g->attendance_30 : null,
            'today_lessons' => isset($g->today_lessons) ? (int) $g->today_lessons : null,
        ];
    }

    private function validated(Request $request, ?ClassGroup $group = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')],
            'homeroom_classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')],
            'advisor_teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'is_active' => ['boolean'],
        ], [], [
            'name' => 'Sınıf adı', 'academic_term_id' => 'Eğitim dönemi', 'program_id' => 'Program', 'homeroom_classroom_id' => 'Ana derslik',
            'advisor_teacher_id' => 'Danışman öğretmen', 'capacity' => 'Kontenjan', 'is_active' => 'Aktiflik',
        ]);
    }
}
