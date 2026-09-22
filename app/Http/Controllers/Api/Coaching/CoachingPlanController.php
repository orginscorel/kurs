<?php

namespace App\Http\Controllers\Api\Coaching;

use App\Http\Controllers\Api\ApiController;
use App\Models\CoachingPlan;
use App\Models\CoachingPlanItem;
use App\Services\Coaching\CoachingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Haftalık çalışma planları. */
class CoachingPlanController extends ApiController
{
    public function __construct(private readonly CoachingService $coaching) {}

    public function index(Request $request): JsonResponse
    {
        $query = CoachingPlan::query()->with(['student:id,full_name,student_no,school_grade', 'coach:id,name', 'items']);

        if ($studentId = $request->integer('student_id')) {
            $query->where('student_id', $studentId);
        }
        if ($coachId = $request->integer('coach_id')) {
            $query->where('coach_id', $coachId);
        }
        if ($week = $request->query('week_start')) {
            $query->whereDate('week_start', CarbonImmutable::parse($week)->startOfWeek()->toDateString());
        }
        $this->applySort($query, $request, ['week_start' => 'week_start'], '-week_start');

        return $this->paginated($query->paginate($this->perPage($request)), fn (CoachingPlan $p) => $this->row($p));
    }

    /** Bir öğrencinin planları (en yeni hafta önce). */
    public function forStudent(Request $request, int $student): JsonResponse
    {
        $plans = CoachingPlan::query()->where('student_id', $student)->with(['coach:id,name', 'items'])
            ->orderByDesc('week_start')->limit(12)->get();

        return response()->json(['data' => $plans->map(fn (CoachingPlan $p) => $this->row($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        [$data, $items] = $this->validated($request);
        $plan = $this->coaching->savePlan($data, $items);

        return response()->json(['message' => 'Haftalık plan kaydedildi.', 'id' => $plan->id], 201);
    }

    public function update(Request $request, CoachingPlan $plan): JsonResponse
    {
        [$data, $items] = $this->validated($request, $plan);
        $this->coaching->updatePlan($plan, $data, $items);

        return $this->ok('Haftalık plan güncellendi.');
    }

    public function destroy(CoachingPlan $plan): JsonResponse
    {
        $this->coaching->deletePlan($plan);

        return $this->ok('Haftalık plan silindi.');
    }

    /** Bir kalemi yaptı/geri al. Plan kalemine erişim şube kapsamıyla plan üzerinden doğrulanır. */
    public function toggleItem(Request $request, CoachingPlanItem $item): JsonResponse
    {
        abort_unless(CoachingPlan::query()->whereKey($item->coaching_plan_id)->exists(), 404);
        $done = $request->boolean('is_done');
        $this->coaching->toggleItem($item, $done);

        return $this->ok($done ? 'Kalem tamamlandı olarak işaretlendi.' : 'Kalem geri alındı.');
    }

    private function row(CoachingPlan $p): array
    {
        $items = $p->relationLoaded('items') ? $p->items : collect();
        $done = $items->where('is_done', true)->count();

        return [
            'id' => $p->id,
            'student' => $p->relationLoaded('student') ? $p->student?->only(['id', 'full_name', 'student_no', 'school_grade']) : null,
            'coach' => $p->relationLoaded('coach') ? $p->coach?->only(['id', 'name']) : null,
            'week_start' => $p->week_start?->toDateString(),
            'note' => $p->note,
            'items_total' => $items->count(),
            'items_done' => $done,
            'progress' => $items->count() ? (int) round($done / $items->count() * 100) : 0,
            'items' => $items->map(fn (CoachingPlanItem $i) => [
                'id' => $i->id,
                'subject' => $i->subject,
                'target_kind' => $i->target_kind,
                'target_kind_label' => CoachingPlanItem::TARGET_KINDS[$i->target_kind] ?? $i->target_kind,
                'target' => $i->target,
                'is_done' => (bool) $i->is_done,
            ])->values(),
        ];
    }

    /** @return array{0: array, 1: array} */
    private function validated(Request $request, ?CoachingPlan $plan = null): array
    {
        $data = $request->validate([
            'student_id' => [$plan ? 'sometimes' : 'required', 'integer', Rule::exists('students', 'id')],
            'coach_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'week_start' => [$plan ? 'sometimes' : 'required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['array'],
            'items.*.subject' => ['nullable', 'string', 'max:120'],
            'items.*.target_kind' => ['nullable', Rule::in(array_keys(CoachingPlanItem::TARGET_KINDS))],
            'items.*.target' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.is_done' => ['nullable', 'boolean'],
        ], [], [
            'student_id' => 'Öğrenci', 'coach_id' => 'Koç', 'week_start' => 'Hafta başı', 'note' => 'Not',
        ]);

        $items = $data['items'] ?? [];
        unset($data['items']);
        if ($plan) {
            $data['student_id'] ??= $plan->student_id;
            $data['week_start'] ??= $plan->week_start?->toDateString();
        }

        return [$data, $items];
    }
}
