<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Http\Controllers\Api\ApiController;
use App\Models\DisciplineBehavior;
use App\Models\DisciplineSanctionType;
use App\Support\Audit;
use App\Support\Discipline\DisciplineCatalog as C;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Davranış kataloğu ve yaptırım kademeleri (discipline.settings). Kullanılmış davranış silinmez, pasife alınır. */
class CatalogController extends ApiController
{
    private function behaviorRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(C::CATEGORIES))],
            'kind' => ['required', Rule::in(['negative', 'positive'])],
            'points' => ['required', 'integer', 'min:0', 'max:50'],
            'severity' => ['nullable', Rule::in(array_keys(C::SEVERITIES))],
            'suggested_sanction' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    private function normalise(array $data): array
    {
        if (($data['kind'] ?? null) === 'positive') {
            $data['severity'] = 'low';
            $data['suggested_sanction'] = null;
            if (! in_array($data['category'], C::POSITIVE_CATEGORIES, true)) {
                abort(response()->json(['message' => 'Olumlu davranış için olumlu bir kategori seçin.', 'errors' => ['category' => ['Olumlu davranış için olumlu bir kategori seçin.']]], 422));
            }
        } elseif (in_array($data['category'] ?? '', C::POSITIVE_CATEGORIES, true)) {
            abort(response()->json(['message' => 'Olumsuz davranış olumlu kategoriye konamaz.', 'errors' => ['category' => ['Olumsuz davranış olumlu kategoriye konamaz.']]], 422));
        }
        if (! empty($data['suggested_sanction']) && ! DisciplineSanctionType::query()->where('code', $data['suggested_sanction'])->exists()) {
            $data['suggested_sanction'] = null;
        }

        return $data;
    }

    public function storeBehavior(Request $request): JsonResponse
    {
        $data = $this->normalise($request->validate($this->behaviorRules(), DisciplinePresenter::messages(), DisciplinePresenter::attributes()));
        $base = Str::slug($data['name'], '_') ?: 'davranis';
        $code = $base;
        for ($i = 2; DisciplineBehavior::query()->where('code', $code)->exists(); $i++) {
            $code = mb_substr($base, 0, 34).'_'.$i;
        }
        $b = DisciplineBehavior::query()->create($data + ['code' => mb_substr($code, 0, 40), 'severity' => 'low', 'sort_order' => (int) DisciplineBehavior::query()->max('sort_order') + 1]);
        Audit::log('discipline.behavior_created', "Disiplin kataloğuna \"{$b->name}\" davranışını ekledi ({$b->points} puan).", $b);

        return response()->json(['message' => 'Davranış eklendi.', 'id' => $b->id], 201);
    }

    public function updateBehavior(Request $request, DisciplineBehavior $behavior): JsonResponse
    {
        $data = $this->normalise($request->validate($this->behaviorRules(), DisciplinePresenter::messages(), DisciplinePresenter::attributes()));
        $used = DB::table('discipline_incident_students')->where('behavior_id', $behavior->id)->exists();
        if ($used && $data['kind'] !== $behavior->kind) {
            return response()->json(['message' => 'Kullanılmış davranışın türü (olumlu/olumsuz) değiştirilemez; yeni davranış ekleyin.', 'error_code' => 'discipline_behavior_used'], 422);
        }
        $behavior->fill($data)->save();
        if ($behavior->wasChanged()) {
            Audit::log('discipline.behavior_updated', "Disiplin kataloğunda \"{$behavior->name}\" davranışını güncelledi.", $behavior, Audit::diff($behavior));
        }

        return $this->ok('Davranış güncellendi.'.($used && $behavior->wasChanged('points') ? ' Yeni puan yalnız bundan sonraki kayıtlara uygulanır.' : ''));
    }

    public function destroyBehavior(DisciplineBehavior $behavior): JsonResponse
    {
        if (DB::table('discipline_incident_students')->where('behavior_id', $behavior->id)->exists()) {
            $behavior->update(['is_active' => false]);
            Audit::log('discipline.behavior_deactivated', "Kullanılmış \"{$behavior->name}\" davranışını pasife aldı.", $behavior);

            return $this->ok('Davranış kayıtlarda kullanıldığı için pasife alındı.');
        }
        Audit::log('discipline.behavior_deleted', "Disiplin kataloğundan \"{$behavior->name}\" davranışını sildi.", $behavior);
        $behavior->delete();

        return $this->ok('Davranış silindi.');
    }

    public function updateType(Request $request, DisciplineSanctionType $type): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'authority' => ['required', Rule::in(['staff', 'board'])],
            'expires_after_days' => ['nullable', 'integer', 'min:1', 'max:1095'],
            'description' => ['nullable', 'string', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes() + ['authority' => 'Karar yetkisi', 'expires_after_days' => 'Düşme süresi']);
        if ($type->is_suspension && $data['authority'] !== 'board') {
            return response()->json(['message' => 'Uzaklaştırma yalnız kurul kararıyla verilebilir.', 'errors' => ['authority' => ['Uzaklaştırma yalnız kurul kararıyla verilebilir.']]], 422);
        }
        $type->fill($data)->save();
        if ($type->wasChanged()) {
            Audit::log('discipline.type_updated', "\"{$type->name}\" yaptırım kademesini güncelledi.", $type, Audit::diff($type));
        }

        return $this->ok('Yaptırım kademesi güncellendi.');
    }
}
