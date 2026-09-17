<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Api\ApiController;
use App\Models\Student;
use App\Models\StudySession;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Services\Academic\StudyService;
use App\Services\Academic\TimeSlots;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudyController extends ApiController
{
    public function __construct(private readonly StudyService $study) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = StudySession::query()->with(['teacher:id,first_name,last_name,color', 'subject:id,name,color', 'classroom:id,name'])->withCount('students')
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('status'), fn ($q, $s) => $s === 'open' ? $q->whereIn('status', ['requested', 'approved']) : $q->where('status', $s))
            ->when($request->integer('teacher_id'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->when($request->integer('student_id'), fn ($q, $id) => $q->whereHas('students', fn ($s) => $s->whereKey($id)))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('topic', 'like', "%$s%")->orWhereHas('teacher', fn ($t) => $t->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%"))->orWhereHas('students', fn ($t) => $t->where('full_name', 'like', "%$s%"))));
        $this->applyDateRange($query, $request, 'starts_at');
        if (! $request->filled('from') && ! $request->filled('to') && ! $request->filled('status')) {
            $query->where('ends_at', '>=', now()->subDays(7));
        }
        $this->applySort($query, $request, ['starts_at' => 'starts_at', 'status' => 'status', 'kind' => 'kind', 'students_count' => 'students_count'], 'starts_at');

        $counts = StudySession::query()->selectRaw('status, COUNT(*) AS c')->where('ends_at', '>=', now()->subDays(7))->groupBy('status')->pluck('c', 'status');

        return $this->paginated($query->paginate($this->perPage($request, 30)), fn (StudySession $s) => $this->row($s, $user), ['status_counts' => $counts, 'statuses' => StudyService::STATUSES]);
    }

    public function show(Request $request, StudySession $study): JsonResponse
    {
        $study->load(['teacher:id,first_name,last_name,color', 'subject:id,name,color', 'classroom:id,name', 'students:id,student_no,full_name,phone,photo_path', 'students.currentClassGroups:id,name']);
        $sensitive = $request->user()->can('students.view_sensitive');
        $requester = $study->requested_by ? \App\Models\User::query()->find($study->requested_by, ['id', 'name']) : null;
        $approver = $study->approved_by ? \App\Models\User::query()->find($study->approved_by, ['id', 'name']) : null;

        return response()->json([
            ...$this->row($study, $request->user()),
            'requested_by' => $requester?->name, 'approved_by' => $approver?->name,
            'students' => $study->students->map(fn (Student $s) => [
                'id' => $s->id, 'student_no' => $s->student_no, 'full_name' => $s->full_name, 'class_group' => $s->currentClassGroups->pluck('name')->join(', '),
                'photo_url' => $s->photo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($s->photo_path) : null,
                'attendance' => $s->pivot->attendance, 'phone' => $sensitive ? $s->phone : Sensitive::maskPhone($s->phone),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $canManage = $request->user()->can('study.manage');
        $session = $this->study->create($data, $request->user(), $canManage && $request->boolean('approve', true));

        return response()->json(['message' => $session->status === 'approved' ? 'Kayıt planlandı.' : 'Talep oluşturuldu; onay bekliyor.', 'id' => $session->id, 'status' => $session->status], 201);
    }

    public function update(Request $request, StudySession $study): JsonResponse
    {
        $this->study->update($study, $this->validated($request, partial: true));

        return $this->ok('Kayıt güncellendi.');
    }

    public function approve(Request $request, StudySession $study): JsonResponse
    {
        $this->study->approve($study, $request->user());

        return $this->ok('Talep onaylandı.');
    }

    public function reject(Request $request, StudySession $study): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $this->study->reject($study, $data['reason'] ?? null);

        return $this->ok('Talep reddedildi.');
    }

    public function cancel(Request $request, StudySession $study): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $this->study->cancel($study, $data['reason'] ?? null);

        return $this->ok('Kayıt iptal edildi.');
    }

    public function addStudents(Request $request, StudySession $study): JsonResponse
    {
        $data = $request->validate(['student_ids' => ['required', 'array', 'min:1', 'max:50'], 'student_ids.*' => ['integer']]);
        $this->study->addStudents($study, $data['student_ids']);

        return $this->ok('Öğrenci eklendi.');
    }

    public function removeStudent(StudySession $study, Student $student): JsonResponse
    {
        $this->study->removeStudent($study, $student->id);

        return $this->ok('Öğrenci çıkarıldı.');
    }

    public function attendance(Request $request, StudySession $study): JsonResponse
    {
        $data = $request->validate(['marks' => ['required', 'array', 'min:1'], 'marks.*' => [Rule::in(['present', 'absent', 'late'])]]);
        $this->study->markAttendance($study, $data['marks']);

        return $this->ok('Katılım kaydedildi.');
    }

    // ------------------------------------------------------------------ öğretmen uygunluğu

    public function availability(Teacher $teacher): JsonResponse
    {
        return response()->json([
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->full_name, 'color' => $teacher->color],
            'slots' => TeacherAvailability::query()->where('teacher_id', $teacher->id)->orderBy('weekday')->orderBy('starts_at')->get(['id', 'weekday', 'starts_at', 'ends_at'])
                ->map(fn ($a) => ['id' => $a->id, 'weekday' => $a->weekday, 'starts_at' => substr($a->starts_at, 0, 5), 'ends_at' => substr($a->ends_at, 0, 5)]),
            'lessons' => $teacher->schedules()->with(['subject:id,name,color', 'classGroup:id,name'])
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))->orderBy('weekday')->orderBy('starts_at')->get()
                ->map(fn ($s) => ['id' => $s->id, 'weekday' => $s->weekday, 'starts_at' => substr($s->starts_at, 0, 5), 'ends_at' => substr($s->ends_at, 0, 5), 'subject' => $s->subject->name, 'color' => $s->subject->color, 'class_group' => $s->classGroup->name]),
            'weekdays' => TimeSlots::WEEKDAYS,
        ]);
    }

    public function setAvailability(Request $request, Teacher $teacher): JsonResponse
    {
        $data = $request->validate([
            'slots' => ['present', 'array', 'max:60'],
            'slots.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'slots.*.starts_at' => ['required', 'date_format:H:i'],
            'slots.*.ends_at' => ['required', 'date_format:H:i'],
        ], [], ['slots' => 'Uygunluk saatleri', 'slots.*.weekday' => 'Gün', 'slots.*.starts_at' => 'Başlangıç saati', 'slots.*.ends_at' => 'Bitiş saati']);
        $this->study->setAvailability($teacher, $data['slots']);

        return $this->ok('Uygunluk takvimi kaydedildi.');
    }

    public function freeSlots(Request $request, Teacher $teacher): JsonResponse
    {
        $date = $request->date('date') ? CarbonImmutable::parse($request->date('date')) : CarbonImmutable::today();
        $min = max(15, min(180, (int) $request->integer('min', 30)));

        return response()->json(['date' => $date->toDateString(), 'weekday' => $date->dayOfWeekIso, ...$this->study->freeSlots($teacher, $date, $min)]);
    }

    // ------------------------------------------------------------------ yardımcılar

    private function row(StudySession $s, $user): array
    {
        return [
            'id' => $s->id, 'kind' => $s->kind, 'kind_label' => $s->kind === 'private' ? 'Birebir' : 'Etüt', 'status' => $s->status, 'status_label' => StudyService::STATUSES[$s->status] ?? $s->status,
            'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(), 'date' => $s->starts_at->toDateString(),
            'start_time' => $s->starts_at->format('H:i'), 'end_time' => $s->ends_at->format('H:i'), 'duration' => (int) $s->starts_at->diffInMinutes($s->ends_at),
            'topic' => $s->topic, 'capacity' => $s->capacity, 'students_count' => (int) ($s->students_count ?? $s->students->count()),
            'teacher' => $s->teacher ? ['id' => $s->teacher->id, 'name' => $s->teacher->full_name, 'color' => $s->teacher->color] : null,
            'subject' => $s->subject ? ['id' => $s->subject->id, 'name' => $s->subject->name, 'color' => $s->subject->color] : null,
            'classroom' => $s->classroom ? ['id' => $s->classroom->id, 'name' => $s->classroom->name] : null,
            'fee' => $user->can('finance.view') || $user->can('study.manage') ? ($s->fee !== null ? (string) $s->fee : null) : null,
            'notes' => $s->notes, 'teacher_id' => $s->teacher_id, 'subject_id' => $s->subject_id, 'classroom_id' => $s->classroom_id,
        ];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'kind' => [$partial ? 'prohibited' : 'required', Rule::in(['study', 'private'])],
            'teacher_id' => [$req, 'integer', Rule::exists('teachers', 'id')],
            'subject_id' => ['nullable', 'integer', Rule::exists('subjects', 'id')],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')],
            'topic' => ['nullable', 'string', 'max:200'],
            'starts_at' => [$req, 'date'],
            'ends_at' => [$req, 'date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:60'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'student_ids' => ['nullable', 'array', 'max:60'], 'student_ids.*' => ['integer', Rule::exists('students', 'id')],
        ], [], [
            'kind' => 'Tür', 'teacher_id' => 'Öğretmen', 'subject_id' => 'Ders', 'classroom_id' => 'Derslik', 'topic' => 'Konu', 'starts_at' => 'Başlangıç',
            'ends_at' => 'Bitiş', 'capacity' => 'Kontenjan', 'fee' => 'Ücret', 'notes' => 'Not', 'student_ids' => 'Öğrenciler', 'student_ids.*' => 'Öğrenci',
        ]);
    }
}
