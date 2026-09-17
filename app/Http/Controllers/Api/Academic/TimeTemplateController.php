<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Api\ApiController;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\TimeTemplate;
use App\Services\Academic\TimeTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Zaman şablonları (sınıf ve saatlere göre ders dilimleri). */
class TimeTemplateController extends ApiController
{
    public function __construct(private readonly TimeTemplateService $service) {}

    public function index(): JsonResponse
    {
        $templates = TimeTemplate::query()->with('classGroups:id,name')->orderBy('name')->get();
        $termIds = AcademicTerm::query()->where('is_current', true)->pluck('id');
        $classes = ClassGroup::query()->with('timeTemplates:id')->where('is_active', true)
            ->when($termIds->isNotEmpty(), fn ($q) => $q->whereIn('academic_term_id', $termIds))->orderBy('name')->get(['id', 'name', 'program_id']);

        return response()->json([
            'data' => $templates->map(fn (TimeTemplate $t) => [
                'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'days' => $t->days, 'generator' => $t->generator, 'levels' => $t->levels ?? [],
                'is_active' => $t->is_active, 'slot_count' => collect($t->days)->sum(fn ($d) => count($d)),
                'classes' => $t->classGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values(),
            ]),
            'classes' => $classes->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'level' => TimeTemplate::levelOf($g->name), 'template_ids' => $g->timeTemplates->pluck('id')->values()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $t = $this->service->create($this->validated($request));

        return response()->json(['message' => 'Zaman şablonu oluşturuldu.', 'id' => $t->id], 201);
    }

    public function update(Request $request, TimeTemplate $template): JsonResponse
    {
        $this->service->update($template, $this->validated($request, $template));

        return $this->ok('Zaman şablonu güncellendi. Mevcut ders programı değişmez; program botunu yeniden çalıştırın.');
    }

    public function destroy(TimeTemplate $template): JsonResponse
    {
        $this->service->delete($template);

        return $this->ok('Zaman şablonu silindi.');
    }

    public function autoAssign(): JsonResponse
    {
        $assigned = $this->service->autoAssign();

        return $this->ok($assigned ? count($assigned).' sınıfa seviyesine göre şablon atandı.' : 'Şablonu olmayan ve seviyesi eşleşen sınıf bulunamadı.', ['assigned' => $assigned]);
    }

    private function validated(Request $request, ?TimeTemplate $t = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('time_templates', 'name')->where('branch_id', app(\App\Support\BranchContext::class)->require())->ignore($t?->id)],
            'description' => ['nullable', 'string', 'max:300'],
            'days' => ['required', 'array'],
            'generator' => ['nullable', 'array'],
            'levels' => ['nullable', 'array'],
            'levels.*' => ['integer', 'min:1', 'max:12'],
            'is_active' => ['nullable', 'boolean'],
            'class_group_ids' => ['nullable', 'array'],
            'class_group_ids.*' => ['integer'],
        ], ['name.unique' => 'Bu adla bir şablon zaten var.', 'days.required' => 'En az bir gün için ders dilimi ekleyin.']);
    }
}
