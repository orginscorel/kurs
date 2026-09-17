<?php

namespace App\Http\Controllers\Api\Guidance;

use App\Http\Controllers\Api\ApiController;
use App\Models\GuidanceMeeting;
use App\Models\Student;
use App\Models\User;
use App\Services\Guidance\GuidancePdf;
use App\Services\Guidance\GuidanceService;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class GuidanceMeetingController extends ApiController
{
    public function __construct(private readonly GuidanceService $guidance) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = GuidanceMeeting::query()->with(['student:id,full_name,student_no,school_grade', 'counselor:id,name']);

        if ($studentId = $request->integer('student_id')) {
            $query->where('student_id', $studentId);
        }
        if ($counselorId = $request->integer('counselor_id')) {
            $query->where('counselor_id', $counselorId);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->whereHas('student', fn (Builder $s) => $s->where('full_name', 'like', '%'.$q.'%'));
        }
        if ($request->boolean('upcoming')) {
            $query->whereNotNull('next_meeting_on')->where('next_meeting_on', '>=', CarbonImmutable::today());
        }
        if ($request->boolean('stale')) {
            // 30+ gündür görüşülmeyen öğrenciler: en son görüşmesi 30 günden eski olan öğrencilerin en güncel kaydı.
            $staleIds = Student::query()->whereIn('status', ['active', 'enrolled', 'frozen'])
                ->whereNotExists(fn ($s) => $s->selectRaw(1)->from('guidance_meetings as gm')->whereColumn('gm.student_id', 'students.id')
                    ->whereNull('gm.deleted_at')->where('gm.met_at', '>=', CarbonImmutable::today()->subDays(30)))
                ->pluck('id');

            return $this->staleStudents($staleIds, $request);
        }
        $this->applyDateRange($query, $request, 'met_at');
        $this->applySort($query, $request, ['met_at' => 'met_at', 'next_meeting_on' => 'next_meeting_on'], '-met_at');

        return $this->paginated($query->paginate($this->perPage($request)), fn (GuidanceMeeting $m) => $this->row($m, $user));
    }

    private function staleStudents($ids, Request $request): JsonResponse
    {
        $query = Student::query()->whereIn('id', $ids)->select('id', 'full_name', 'student_no', 'school_grade', 'phone')
            ->addSelect(['last_meeting_at' => GuidanceMeeting::query()->selectRaw('MAX(met_at)')
                ->whereColumn('student_id', 'students.id')->whereNull('deleted_at')]);
        $paginator = $query->orderBy('full_name')->paginate($this->perPage($request));

        return $this->paginated($paginator, fn (Student $s) => [
            'id' => null,
            'student' => ['id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'school_grade' => $s->school_grade],
            'last_meeting_at' => $s->last_meeting_at,
            'is_stale_row' => true,
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'kinds' => GuidanceMeeting::KINDS,
            'visibilities' => ['counselor' => 'Yalnız rehber', 'staff' => 'Personel', 'guardian' => 'Veli de görebilir'],
            'counselors' => User::query()->where('is_active', true)->whereIn('user_type', ['staff', 'teacher'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, GuidanceMeeting $meeting): JsonResponse
    {
        $meeting->load(['student:id,full_name,student_no,school_grade', 'counselor:id,name']);

        return response()->json(['data' => $this->row($meeting, $request->user(), full: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $meeting = $this->guidance->create($data);

        return response()->json(['message' => 'Görüşme kaydedildi.', 'id' => $meeting->id], 201);
    }

    public function update(Request $request, GuidanceMeeting $meeting): JsonResponse
    {
        $data = $this->validated($request, $meeting);
        $this->guidance->update($meeting, $data);

        return $this->ok('Görüşme kaydı güncellendi.');
    }

    public function destroy(GuidanceMeeting $meeting): JsonResponse
    {
        $this->guidance->delete($meeting);

        return $this->ok('Görüşme kaydı silindi.');
    }

    public function reportPdf(Request $request, Student $student, GuidancePdf $pdf): Response
    {
        Audit::log('guidance.report_generated', "{$student->full_name} için rehberlik raporu PDF oluşturdu.", $student);

        return $pdf->studentReport($student, inline: $request->boolean('download') === false);
    }

    private function row(GuidanceMeeting $m, User $user, bool $full = false): array
    {
        $canPrivate = GuidanceService::canSeePrivateNote($user, $m);
        if ($canPrivate && $m->private_note) {
            Audit::log('guidance.private_note_viewed', "{$m->student?->full_name} öğrencisinin gizli rehberlik notunu görüntüledi.", $m->student);
        }

        return [
            'id' => $m->id,
            'student' => $m->relationLoaded('student') ? $m->student?->only(['id', 'full_name', 'student_no', 'school_grade']) : null,
            'counselor' => $m->relationLoaded('counselor') ? $m->counselor?->only(['id', 'name']) : null,
            'met_at' => $m->met_at,
            'kind' => $m->kind,
            'kind_label' => GuidanceMeeting::KINDS[$m->kind] ?? $m->kind,
            'summary' => $m->summary,
            'goal' => $m->goal,
            'motivation' => $m->motivation,
            'study_discipline' => $m->study_discipline,
            'visibility' => $m->visibility,
            'visible_to_student' => (bool) $m->visible_to_student,
            'next_meeting_on' => $m->next_meeting_on,
            'private_note' => $canPrivate ? $m->private_note : null,
            'can_see_private' => $canPrivate,
        ];
    }

    private function validated(Request $request, ?GuidanceMeeting $meeting = null): array
    {
        $canPrivate = $request->user()->can('guidance.private');

        $data = $request->validate([
            'student_id' => [$meeting ? 'sometimes' : 'required', 'integer', Rule::exists('students', 'id')],
            'counselor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'met_at' => ['required', 'date'],
            'kind' => ['required', Rule::in(array_keys(GuidanceMeeting::KINDS))],
            'summary' => ['required', 'string', 'max:3000'],
            'goal' => ['nullable', 'string', 'max:500'],
            'motivation' => ['nullable', 'integer', 'min:1', 'max:5'],
            'study_discipline' => ['nullable', 'integer', 'min:1', 'max:5'],
            'private_note' => ['nullable', 'string', 'max:3000'],
            'visibility' => ['required', Rule::in(['counselor', 'staff', 'guardian'])],
            // Öğrenci portalında özet görünsün mü? "Yalnız rehber" kayıtları öğrenciye asla açılmaz.
            'visible_to_student' => ['sometimes', 'boolean'],
            'next_meeting_on' => ['nullable', 'date'],
        ], [], [
            'student_id' => 'Öğrenci', 'counselor_id' => 'Rehber öğretmen', 'met_at' => 'Görüşme tarihi', 'kind' => 'Görüşme türü', 'summary' => 'Görüşme özeti',
            'goal' => 'Belirlenen hedef', 'motivation' => 'Motivasyon düzeyi', 'study_discipline' => 'Çalışma düzeni', 'private_note' => 'Gizli not',
            'visibility' => 'Kayıt görünürlüğü', 'visible_to_student' => 'Öğrenci portalında göster', 'next_meeting_on' => 'Sonraki görüşme tarihi',
        ]);

        if (($data['visibility'] ?? null) === 'counselor') {
            $data['visible_to_student'] = false;
        }

        if (! $canPrivate) {
            unset($data['private_note']);
        }

        return $data;
    }
}
