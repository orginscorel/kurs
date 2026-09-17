<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Middleware\EnsurePortalTeacher;
use App\Models\DisciplineBehavior;
use App\Models\DisciplineIncident;
use App\Services\Discipline\DisciplineService;
use App\Services\Discipline\DisciplineSettings;
use App\Services\Teachers\TeacherScope;
use App\Support\Discipline\DisciplineCatalog as C;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Öğretmen portalı › "Olay bildir". Öğretmen yalnız kendi sınıflarındaki öğrenciler için olay/olumlu davranış bildirir;
 * karar vermez. Bildirim "Açık" düşer, karar yetkililerine uygulama içi bildirim gider (mesaj gönderilmez).
 */
class DisciplineTeacherController extends ApiController
{
    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) DisciplineSettings::get('teacher_portal_reporting'),
            'behaviors' => DisciplineBehavior::query()->where('is_active', true)->orderBy('kind')->orderBy('sort_order')
                ->get(['id', 'name', 'category', 'kind', 'points', 'severity'])
                ->map(fn ($b) => $b->toArray() + ['category_label' => C::CATEGORIES[$b->category] ?? $b->category]),
            'categories' => C::CATEGORIES,
            'severities' => C::SEVERITIES,
            'roles' => C::ROLES,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = DisciplineIncident::query()->where('reported_by', $request->user()->id)
            ->with(['participants.student:id,full_name,student_no', 'participants.behavior:id,name'])
            ->orderByDesc('occurred_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn (DisciplineIncident $i) => [
            'id' => $i->id, 'incident_no' => $i->incident_no, 'kind' => $i->kind, 'occurred_at' => $i->occurred_at->toAtomString(),
            'status' => $i->status, 'status_label' => C::INCIDENT_STATUSES[$i->status] ?? $i->status, 'location' => $i->location,
            'students' => $i->participants->where('role', 'involved')->map(fn ($p) => ['full_name' => $p->student?->full_name, 'behavior' => $p->behavior?->name])->values(),
        ])]);
    }

    public function store(Request $request, DisciplineService $discipline): JsonResponse
    {
        if (! DisciplineSettings::get('teacher_portal_reporting')) {
            throw new BusinessRuleException('Kurum öğretmen portalından olay bildirimini kapatmış.', 'discipline_teacher_reporting_off', [], 403);
        }
        /** @var TeacherScope $scope */
        $scope = $request->attributes->get(EnsurePortalTeacher::SCOPE_ATTRIBUTE);
        $teacher = $request->attributes->get(EnsurePortalTeacher::ATTRIBUTE);

        $data = $request->validate([
            'occurred_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:120'],
            'class_group_id' => ['nullable', 'integer'],
            'subject_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'min:5', 'max:3000'],
            'witnesses' => ['nullable', 'string', 'max:500'],
            'students' => ['required', 'array', 'min:1', 'max:10'],
            'students.*.student_id' => ['required', 'integer'],
            'students.*.behavior_id' => ['nullable', 'integer'],
            'students.*.role' => ['nullable', Rule::in(array_keys(C::ROLES))],
        ], DisciplinePresenter::messages() + ['description.min' => 'Olayı kısaca anlatın (en az :min karakter).'], DisciplinePresenter::attributes());

        foreach ($data['students'] as $s) {
            $scope->assertStudent((int) $s['student_id']);
        }
        if (! empty($data['class_group_id'])) {
            $scope->assertGroup((int) $data['class_group_id']);
        }
        if (! empty($data['subject_id']) && ! $scope->subjectIds()->contains((int) $data['subject_id'])) {
            throw new BusinessRuleException('Bu ders sizin derslerinizden değil.', 'teacher_scope_subject', [], 422);
        }

        $incident = $discipline->createIncident([
            'occurred_at' => $data['occurred_at'] ?? now()->toDateTimeString(),
            'location' => $data['location'] ?? null,
            'class_group_id' => $data['class_group_id'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'teacher_id' => $teacher->id,
            'description' => $data['description'],
            'witnesses' => $data['witnesses'] ?? null,
            'students' => $data['students'],
            'source' => 'teacher_portal',
        ], $request->user(), notifyStaff: true);

        return response()->json([
            'message' => $incident->kind === 'positive' ? 'Olumlu davranış kaydedildi.' : 'Olay bildirildi; disiplin sorumlusu inceleyecek.',
            'id' => $incident->id, 'incident_no' => $incident->incident_no,
        ], 201);
    }
}
