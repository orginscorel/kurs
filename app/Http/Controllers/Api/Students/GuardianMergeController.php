<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Api\ApiController;
use App\Models\Guardian;
use App\Services\Guardians\GuardianMergeService;
use App\Support\Sensitive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mükerrer veli: aynı telefonlu gruplar, birleştirme önizlemesi ve onaylı birleştirme. */
class GuardianMergeController extends ApiController
{
    public function __construct(private readonly GuardianMergeService $merger) {}

    /** ?guardian_id=… verilirse yalnız o velinin grubu döner. */
    public function duplicates(Request $request): JsonResponse
    {
        $groups = collect($this->merger->duplicateGroups());
        if ($id = $request->integer('guardian_id')) {
            $groups = $groups->filter(fn ($g) => in_array($id, $g['guardian_ids'], true))->values();
        }
        $sensitive = $request->user()->can('students.view_sensitive');

        $ids = $groups->pluck('guardian_ids')->flatten()->unique()->all();
        $people = Guardian::query()->whereIn('id', $ids)->with(['students:id,full_name,status', 'user:id,is_active,last_login_at'])->get()->keyBy('id');

        return response()->json([
            'data' => $groups->map(fn ($g) => [
                'key' => $g['key'],
                'guardians' => collect($g['guardian_ids'])->map(fn ($gid) => $people->get($gid))->filter()->map(fn (Guardian $p) => [
                    'id' => $p->id,
                    'name' => $p->full_name,
                    'phone' => $sensitive ? $p->phone : Sensitive::maskPhone($p->phone),
                    'email' => $p->email,
                    'created_at' => $p->created_at?->toAtomString(),
                    'students' => $p->students->map(fn ($s) => ['id' => $s->id, 'full_name' => $s->full_name, 'status' => $s->status])->values(),
                    'portal' => $p->user ? ['is_active' => (bool) $p->user->is_active, 'last_login_at' => $p->user->last_login_at?->toAtomString()] : null,
                ])->values(),
            ])->values(),
            'meta' => ['groups' => $groups->count(), 'guardians' => count($ids)],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer'],
            'source_id' => ['required', 'integer', 'different:target_id'],
        ], ['source_id.different' => 'Birleştirilecek iki farklı veli seçin.']);

        return response()->json(['data' => $this->merger->preview((int) $data['target_id'], (int) $data['source_id'])]);
    }

    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer'],
            'source_id' => ['required', 'integer', 'different:target_id'],
            'fingerprint' => ['required', 'string', 'size:64'],
            'confirm' => ['required', 'accepted'],
        ], [
            'source_id.different' => 'Birleştirilecek iki farklı veli seçin.',
            'fingerprint.required' => 'Önce önizlemeyi açın.',
            'confirm.accepted' => 'Birleştirmeyi onaylayın.',
        ]);

        $result = $this->merger->merge((int) $data['target_id'], (int) $data['source_id'], $data['fingerprint']);

        return $this->ok($result['message'], ['data' => $result]);
    }
}
