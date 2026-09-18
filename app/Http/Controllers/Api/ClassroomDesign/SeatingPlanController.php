<?php

namespace App\Http\Controllers\Api\ClassroomDesign;

use App\Http\Controllers\Api\ApiController;
use App\Models\ClassGroup;
use App\Models\ClassroomLayout;
use App\Services\ClassroomDesign\SeatingPlanService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SINIF OTURMA PLANI (yönetim). Okuma academic.view; yazma academic.manage ya da classroom_layouts.manage.
 * Oda düzeni burada değişmez — yalnız masa ↔ öğrenci ve masa durumu (rezerve/kullanılamaz).
 */
class SeatingPlanController extends ApiController
{
    public function __construct(private readonly SeatingPlanService $service) {}

    public function show(Request $request, ClassGroup $group): JsonResponse
    {
        $classroomId = $request->integer('classroom_id') ?: null;
        $user = $request->user();

        return response()->json([
            ...$this->service->payload($group->id, $classroomId),
            'can_edit' => (bool) ($user?->can('academic.manage') || $user?->can('classroom_layouts.manage')),
            'can_edit_room' => (bool) $user?->can('classroom_layouts.manage'),
        ]);
    }

    public function update(Request $request, ClassGroup $group): JsonResponse
    {
        $v = $request->validate([
            'classroom_layout_id' => ['required', 'integer', Rule::exists('classroom_layouts', 'id')->where('branch_id', $group->branch_id)->whereNull('deleted_at')],
            'seats' => ['present', 'array', 'max:800'],
            'seats.*' => ['array', 'max:8'],
            'seats.*.*' => ['nullable', 'string', 'max:40'],
            'statuses' => ['present', 'array', 'max:800'],
            'statuses.*' => ['string', Rule::in(SeatingPlanService::STATUSES)],
        ], [], ['classroom_layout_id' => 'Oda düzeni', 'seats' => 'Oturma planı', 'statuses' => 'Masa durumları']);

        $plan = $this->service->save($group->id, (int) $v['classroom_layout_id'], $request->input('seats', []), $request->input('statuses', []), Auth::id());
        $count = collect($plan->seats)->flatten()->filter()->count();
        Audit::log('seating_plan.saved', "{$group->name} sınıfının oturma planını kaydetti ({$count} öğrenci).", $plan);

        return response()->json(['message' => "Oturma planı kaydedildi ({$count} öğrenci).", 'id' => $plan->id, 'updated_at' => optional($plan->updated_at)->toIso8601String()]);
    }

    /** Sınıflar listesi için: sınıf → derslik, oda düzeni küçük görseli, yerleşmiş öğrenci sayısı */
    public function overview(): JsonResponse
    {
        $groups = ClassGroup::query()->where('is_active', true)->get(['id', 'homeroom_classroom_id']);
        $out = [];
        $classroomIds = [];
        $pick = [];
        foreach ($groups as $g) {
            $room = $this->service->classrooms($g->id)->first(fn ($r) => $r['layout'] !== null);
            if ($room) {
                $pick[$g->id] = $room;
                $classroomIds[] = $room['id'];
            }
        }
        $plans = DB::table('class_seating_plans')->whereIn('class_group_id', array_keys($pick))->get(['class_group_id', 'classroom_layout_id', 'seats']);
        foreach ($groups as $g) {
            $room = $pick[$g->id] ?? null;
            $seated = 0;
            if ($room) {
                $row = $plans->first(fn ($p) => (int) $p->class_group_id === $g->id && (int) $p->classroom_layout_id === (int) $room['layout']['id']);
                $seated = $row ? collect(json_decode((string) $row->seats, true) ?: [])->flatten()->filter()->count() : 0;
            }
            $out[] = [
                'group_id' => $g->id,
                'classroom' => $room ? ['id' => $room['id'], 'name' => $room['name']] : null,
                'layout_id' => $room['layout']['id'] ?? null,
                'thumbnail_url' => $room['layout']['thumbnail_url'] ?? null,
                'seated' => $seated,
            ];
        }

        return response()->json(['data' => $out]);
    }
}
