<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Api\ApiController;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\Program;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeTemplate;
use App\Services\Academic\ClassCurriculum;
use App\Services\Placement\ClassStructure;
use App\Services\Placement\PlacementService;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sınıf yapısı sihirbazı: seviye/şube/alan/kapasite/program/şablon tanımı (kurum ayarı),
 * döneme uygulama (önizleme + onay), alan müfredatları ve sınıfa özel ders saatleri.
 */
class ClassStructureController extends ApiController
{
    public function __construct(
        private readonly ClassStructure $structure,
        private readonly ClassCurriculum $curriculum,
        private readonly PlacementService $placement,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $term = $this->placement->term($request->integer('term_id') ?: null);

        return response()->json($this->payload($term));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')],
            'structure' => ['required', 'array'],
            'structure.default_capacity' => ['required', 'integer', 'min:1', 'max:60'],
            'structure.track_defaults' => ['nullable', 'array'],
            'structure.levels' => ['required', 'array', 'min:1', 'max:14'],
            'structure.levels.*.grade' => ['required', 'integer', 'min:1', 'max:13'],
            'structure.levels.*.sectioned' => ['required', 'boolean'],
            'structure.levels.*.sections' => ['present', 'array', 'max:'.ClassStructure::MAX_SECTIONS],
            'structure.levels.*.sections.*.code' => ['nullable', 'string', 'max:1'],
            'structure.levels.*.sections.*.track' => ['nullable', Rule::in(array_keys(ClassStructure::TRACKS))],
            'structure.levels.*.sections.*.capacity' => ['required', 'integer', 'min:1', 'max:60'],
            'structure.levels.*.sections.*.program_id' => ['nullable', 'integer'],
            'structure.levels.*.sections.*.time_template_ids' => ['nullable', 'array'],
            'structure.levels.*.sections.*.time_template_ids.*' => ['integer'],
            'structure.levels.*.sections.*.homeroom_classroom_id' => ['nullable', 'integer'],
            'structure.levels.*.sections.*.advisor_teacher_id' => ['nullable', 'integer'],
            'structure.levels.*.sections.*.short_name' => ['nullable', 'string', 'max:20'],
            'structure.levels.*.sections.*.color' => ['nullable', Rule::in(ClassStructure::COLORS)],
        ], [
            'structure.levels.required' => 'En az bir sınıf seviyesi tanımlayın.',
            'structure.levels.*.sections.*.capacity.max' => 'Şube kapasitesi en fazla 60 olabilir.',
            'structure.levels.*.sections.*.code.max' => 'Şube adı tek harf olmalı.',
        ]);

        $normalized = ClassStructure::normalize($this->sanitizeRefs($data['structure']));
        $before = $this->structure->structure();
        Settings::put('classes', ['structure' => ClassStructure::storable($normalized), 'capacity' => $normalized['default_capacity']]);
        $this->structure->flush();

        $summary = implode(', ', array_map(fn ($l) => $l['label'].' '.($l['sectioned'] ? implode('/', array_column($l['sections'], 'code')) : 'şubesiz'), $normalized['levels']));
        Audit::log('settings.class_structure_updated', "Sınıf yapısını güncelledi: {$summary}. (Mevcut sınıflar \"Yapıyı uygula\" ile değişir.)", null,
            ['before' => ClassStructure::storable($before), 'after' => ClassStructure::storable($normalized)]);

        $term = $this->placement->term($data['term_id'] ?? null);

        return $this->ok('Sınıf yapısı kaydedildi. Sınıfları açmak/güncellemek için "Yapıyı uygula" adımını kullanın.', $this->payload($term));
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
            'update_existing' => ['required', 'boolean'],
        ]);
        $term = AcademicTerm::query()->findOrFail($data['term_id']);
        $r = $this->placement->ensureStructure($term, (bool) $data['update_existing']);
        $parts = [];
        if ($r['created']) {
            $parts[] = count($r['created']).' sınıf açıldı';
        }
        if ($r['adopted'] || $r['reactivated']) {
            $parts[] = count($r['adopted']) + count($r['reactivated']).' sınıf eşleştirildi';
        }
        if ($r['updated']) {
            $parts[] = count($r['updated']).' sınıf güncellendi';
        }

        return $this->ok($parts ? 'Sınıf yapısı uygulandı: '.implode(', ', $parts).'.' : 'Sınıflar yapıyla zaten uyumlu.', ['result' => $r] + $this->payload($term));
    }

    public function updateTrackCurricula(Request $request): JsonResponse
    {
        $data = $request->validate([
            'curricula' => ['required', 'array'],
            'curricula.*' => ['array'],
            'curricula.*.*' => ['integer', 'min:0', 'max:'.ClassCurriculum::MAX_WEEKLY],
        ]);

        return $this->ok('Alan müfredatları kaydedildi.', ['track_curricula' => $this->curriculum->saveTrackCurricula($data['curricula'])]);
    }

    public function applyTrack(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_group_ids' => ['required', 'array', 'min:1', 'max:100'],
            'class_group_ids.*' => ['integer'],
        ], ['class_group_ids.required' => 'En az bir sınıf seçin.']);
        $r = $this->curriculum->applyTrack($data['class_group_ids']);
        $msg = $r['applied'] ? 'Alan müfredatı uygulandı: '.implode(', ', $r['applied']).'.' : 'Hiçbir sınıfa uygulanmadı.';
        if ($r['skipped']) {
            $msg .= ' Alanı seçilmemiş: '.implode(', ', $r['skipped']).'.';
        }

        return $this->ok($msg, $r);
    }

    public function curriculum(ClassGroup $group): JsonResponse
    {
        return response()->json($this->curriculum->forGroup($group));
    }

    public function saveCurriculum(Request $request, ClassGroup $group): JsonResponse
    {
        $data = $request->validate([
            'track' => ['nullable', Rule::in(array_keys(ClassStructure::TRACKS))],
            'rows' => ['present', 'array', 'max:200'],
            'rows.*.subject_id' => ['required', 'integer'],
            'rows.*.hours' => ['required', 'integer', 'min:0', 'max:'.ClassCurriculum::MAX_WEEKLY],
            'rows.*.teacher_id' => ['nullable', 'integer'],
            'rows.*.max_per_day' => ['nullable', 'integer', 'min:1', 'max:6'],
            'rows.*.block_size' => ['nullable', 'integer', Rule::in([1, 2])],
        ], ['rows.*.hours.max' => 'Bir ders için haftalık en fazla '.ClassCurriculum::MAX_WEEKLY.' saat girilebilir.']);

        if ($request->has('track') && $group->track !== ($data['track'] ?? null)) {
            $from = $group->track;
            $group->forceFill(['track' => $data['track'] ?? null])->save();
            Audit::log('class_group.track_updated', "{$group->name} sınıfının alanını değiştirdi: ".($from ? ClassStructure::TRACKS[$from] ?? $from : '—').' → '.($group->track ? ClassStructure::TRACKS[$group->track] : '—').'.', $group);
        }
        $r = $this->curriculum->save($group, $data['rows']);

        return $this->ok($r['changed'] ? 'Sınıf müfredatı kaydedildi.' : 'Değişiklik yok.', $this->curriculum->forGroup($group->fresh()));
    }

    // ------------------------------------------------------------------ yardımcılar

    private function payload(AcademicTerm $term): array
    {
        $structure = $this->structure->structure();
        $plan = $this->structure->plan($term);
        $ids = array_values(array_filter(array_column($plan, 'class_group_id')));
        $groups = ClassGroup::query()->withTrashed()->with(['timeTemplates' => fn ($q) => $q->where('is_active', true), 'program:id,name'])->whereIn('id', $ids)->get()->keyBy('id');
        $sizes = DB::table('class_group_student')->whereIn('class_group_id', $ids)->whereNull('left_on')->groupBy('class_group_id')->pluck(DB::raw('COUNT(*)'), 'class_group_id');
        $effective = $this->curriculum->effective($groups->values());
        $overrides = DB::table('class_group_subject_hours')->whereIn('class_group_id', $ids)->groupBy('class_group_id')->pluck(DB::raw('COUNT(*)'), 'class_group_id');
        $programs = Program::query()->orderBy('name')->get(['id', 'name', 'code', 'is_active']);
        $templates = TimeTemplate::query()->where('is_active', true)->orderBy('name')->get();

        $plan = array_map(function ($row) use ($groups, $sizes, $effective, $overrides) {
            $g = $row['class_group_id'] ? ($groups[$row['class_group_id']] ?? null) : null;

            return $row + ['current' => $g ? [
                'id' => $g->id, 'name' => $g->name, 'size' => (int) ($sizes[$g->id] ?? 0), 'capacity' => (int) $g->capacity, 'track' => $g->track,
                'program' => $g->program?->name, 'program_id' => (int) $g->program_id, 'is_active' => (bool) $g->is_active && ! $g->trashed(),
                'weekly_hours' => array_sum(array_column($effective[$g->id] ?? [], 'hours')), 'custom_hours' => (int) ($overrides[$g->id] ?? 0),
                'template_slots' => $g->timeTemplates->sum(fn ($t) => count($t->periods())),
                'templates' => $g->timeTemplates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            ] : null];
        }, $plan);

        $unstructured = ClassGroup::query()->where('academic_term_id', $term->id)->where('is_active', true)
            ->whereNotIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'name', 'track', 'program_id']);

        return [
            'term' => ['id' => $term->id, 'name' => $term->name],
            'terms' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'is_current']),
            'customized' => $this->structure->isCustomized(),
            'configured' => $this->structure->isConfigured(),
            'structure' => $structure,
            'plan' => $plan,
            'unstructured' => $unstructured->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'track' => $g->track])->values(),
            'options' => [
                'tracks' => ClassStructure::TRACKS,
                'colors' => ClassStructure::COLORS,
                'programs' => $programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code])->values(),
                'templates' => $templates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slots' => count($t->periods()), 'levels' => $t->levels ?? []])->values(),
                'classrooms' => Classroom::query()->where('is_active', true)->where('kind', '!=', 'study')->orderBy('name')->get(['id', 'name', 'capacity']),
                'teachers' => Teacher::query()->where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->full_name])->values(),
                'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'code']),
                'level_programs' => array_map(fn ($v) => $v[0], ClassStructure::LEVEL_PROGRAMS),
                'track_programs' => ClassStructure::TRACK_PROGRAMS,
            ],
            'track_curricula' => $this->curriculum->trackCurricula(),
        ];
    }

    /** Var olmayan program/şablon/derslik/öğretmen kimliklerini temizler. */
    private function sanitizeRefs(array $structure): array
    {
        $programs = Program::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
        $templates = TimeTemplate::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
        $rooms = Classroom::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
        $teachers = Teacher::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
        foreach ($structure['levels'] as &$l) {
            foreach ($l['sections'] as &$s) {
                if (! empty($s['program_id']) && ! in_array((int) $s['program_id'], $programs, true)) {
                    $s['program_id'] = null;
                }
                $s['time_template_ids'] = array_values(array_filter((array) ($s['time_template_ids'] ?? []), fn ($id) => in_array((int) $id, $templates, true)));
                if (! empty($s['homeroom_classroom_id']) && ! in_array((int) $s['homeroom_classroom_id'], $rooms, true)) {
                    $s['homeroom_classroom_id'] = null;
                }
                if (! empty($s['advisor_teacher_id']) && ! in_array((int) $s['advisor_teacher_id'], $teachers, true)) {
                    $s['advisor_teacher_id'] = null;
                }
            }
            unset($s);
        }
        unset($l);

        return $structure;
    }
}
