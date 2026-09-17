<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ApiController;
use App\Models\School;
use App\Support\Audit;
use App\Support\SchoolCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Kurumun okul listesi: formlarda öneri, ayarlarda düzenleme, bölge kataloğundan içe alma. */
class SchoolController extends ApiController
{
    /** Formlar için etkin okullar (tüm personel). */
    public function options(): JsonResponse
    {
        $rows = School::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'kind', 'city', 'district']);

        return response()->json(['data' => $rows->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'district' => trim(($s->city ? $s->city.' / ' : '').($s->district ?? ''), ' /') ?: null])->values()]);
    }

    public function index(): JsonResponse
    {
        $rows = School::query()->orderBy('sort_order')->orderBy('name')->get();
        $used = \Illuminate\Support\Facades\DB::table('students')->whereNull('deleted_at')->whereNotNull('school_name')
            ->selectRaw('school_name, COUNT(*) as c')->groupBy('school_name')->pluck('c', 'school_name');

        return response()->json([
            'data' => $rows->map(fn (School $s) => $s->only(['id', 'name', 'kind', 'city', 'district', 'is_active', 'sort_order']) + ['students' => (int) ($used[$s->name] ?? 0)])->values(),
            'kinds' => SchoolCatalog::KINDS,
            'regions' => SchoolCatalog::regions(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $school = School::query()->create($data + ['sort_order' => (int) School::query()->max('sort_order') + 1]);
        Audit::log('school.created', "Okul listesine eklendi: {$school->name}", $school);

        return response()->json(['message' => 'Okul eklendi.', 'id' => $school->id], 201);
    }

    public function update(Request $request, School $school): JsonResponse
    {
        $school->update($this->validated($request, $school));
        Audit::log('school.updated', "Okul güncellendi: {$school->name}", $school);

        return $this->ok('Okul güncellendi.');
    }

    public function destroy(School $school): JsonResponse
    {
        $name = $school->name;
        $school->delete();
        Audit::log('school.deleted', "Okul listeden çıkarıldı: {$name}");

        return $this->ok('Okul listeden çıkarıldı. Bu okulu yazan öğrenci kayıtları değişmez.');
    }

    /** Bölge kataloğundan içe alma (var olanlar atlanır). */
    public function importRegions(Request $request): JsonResponse
    {
        $data = $request->validate(['regions' => ['required', 'array', 'min:1'], 'regions.*' => [Rule::in(array_keys(SchoolCatalog::REGIONS))]],
            ['regions.required' => 'En az bir bölge seçin.']);
        $added = 0;
        $order = (int) School::query()->max('sort_order');
        foreach ($data['regions'] as $key) {
            $r = SchoolCatalog::REGIONS[$key];
            foreach ($r['schools'] as [$name, $kind]) {
                $s = School::query()->firstOrCreate(['name' => $name], ['kind' => $kind, 'city' => $r['city'], 'district' => $r['district'], 'is_active' => true, 'sort_order' => ++$order]);
                $added += $s->wasRecentlyCreated ? 1 : 0;
            }
        }
        Audit::log('school.imported', "Bölge okulları içe alındı ({$added} yeni).");

        return $this->ok($added ? "{$added} okul eklendi." : 'Seçilen bölgelerin okulları zaten listede.', ['added' => $added]);
    }

    private function validated(Request $request, ?School $school = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('schools', 'name')->where('branch_id', app(\App\Support\BranchContext::class)->require())->ignore($school?->id)],
            'kind' => ['nullable', Rule::in(array_keys(SchoolCatalog::KINDS))],
            'city' => ['nullable', 'string', 'max:60'],
            'district' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ], ['name.unique' => 'Bu okul zaten listede.', 'name.required' => 'Okul adını yazın.']);
        $data['kind'] = $data['kind'] ?? 'diger';

        return $data;
    }
}
