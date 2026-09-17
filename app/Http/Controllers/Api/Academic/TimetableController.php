<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Api\ApiController;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Teacher;
use App\Models\TimetableRun;
use App\Services\Academic\ClassCurriculum;
use App\Services\Academic\Timetable\TimetableService;
use App\Services\Academic\Timetable\Weights;
use App\Services\Placement\ClassStructure;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Program botu: seçenekler, çalıştırmalar (öneri), önizleme, uygula / vazgeç / geri al. */
class TimetableController extends ApiController
{
    public function __construct(private readonly TimetableService $service, private readonly ClassCurriculum $curriculum) {}

    public function options(Request $request): JsonResponse
    {
        $terms = AcademicTerm::query()->orderByDesc('is_current')->orderByDesc('starts_on')->get(['id', 'name', 'starts_on', 'ends_on', 'is_current']);
        $termId = $request->integer('term_id') ?: $terms->first()?->id;

        $groups = ClassGroup::query()->with(['program:id,name', 'timeTemplates' => fn ($q) => $q->where('is_active', true), 'homeroom:id,name'])
            ->where('academic_term_id', $termId)->where('is_active', true)->orderBy('name')->get();
        $sizes = DB::table('class_group_student')->whereIn('class_group_id', $groups->pluck('id'))->whereNull('left_on')->groupBy('class_group_id')->pluck(DB::raw('COUNT(*)'), 'class_group_id');
        $effective = $this->curriculum->effective($groups);
        $overrides = DB::table('class_group_subject_hours')->whereIn('class_group_id', $groups->pluck('id'))->groupBy('class_group_id')->pluck(DB::raw('COUNT(*)'), 'class_group_id');
        $schedules = DB::table('lesson_schedules')->whereNull('deleted_at')->whereIn('class_group_id', $groups->pluck('id'))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->groupBy('class_group_id')->selectRaw('class_group_id, COUNT(*) AS total, SUM(is_locked) AS locked')->get()->keyBy('class_group_id');

        return response()->json([
            'terms' => $terms,
            'term_id' => $termId,
            'classes' => $groups->map(function ($g) use ($sizes, $effective, $overrides, $schedules) {
                [$level, $section] = ClassStructure::resolve($g->name, $g->grade_level, $g->section);
                if ($level === null && preg_match('/^\s*(\d{1,2})\b/u', $g->name, $m)) {
                    $level = (int) $m[1]; // yapı dışı eski sınıf adı ("12-SAY-A")
                }

                return [
                    'id' => $g->id, 'name' => $g->name, 'level' => $level, 'level_label' => $level ? ClassStructure::levelLabel($level) : null,
                    'section' => $section === ClassStructure::NO_SECTION ? null : $section, 'track' => $g->track,
                    'structured' => $g->grade_level !== null,
                    'program' => $g->program?->name, 'homeroom' => $g->homeroom?->name,
                    'size' => (int) ($sizes[$g->id] ?? 0), 'capacity' => $g->capacity,
                    'weekly_hours' => array_sum(array_column($effective[$g->id] ?? [], 'hours')), 'custom_hours' => (int) ($overrides[$g->id] ?? 0),
                    'demand' => array_map(fn ($r) => [$r['subject'], $r['hours'], $r['teacher']], $effective[$g->id] ?? []),
                    'templates' => $g->timeTemplates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
                    'template_slots' => $g->timeTemplates->sum(fn ($t) => count($t->periods())),
                    'lessons' => (int) ($schedules[$g->id]->total ?? 0), 'locked' => (int) ($schedules[$g->id]->locked ?? 0),
                ];
            })->values(),
            'teachers' => $this->teacherRows($termId, $groups, $effective),
            'tracks' => ClassStructure::TRACKS,
            'weights' => Weights::DEFAULTS,
            'weight_labels' => Weights::LABELS,
            'defaults' => TimetableService::defaultSettings(),
        ]);
    }

    /** Bot ekranındaki öğretmen tablosu: branş, mevcut yük, talep (branşına düşen saat), üst sınır, hedef, dahil/hariç. */
    private function teacherRows(?int $termId, $groups, array $effective): array
    {
        $teachers = Teacher::query()->with('subjects:id,name')->where('is_active', true)->orderBy('first_name')->orderBy('last_name')->get();
        $today = now()->toDateString();
        $load = DB::table('lesson_schedules')->whereNull('deleted_at')->where('academic_term_id', $termId)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->groupBy('teacher_id')->pluck(DB::raw('COUNT(*)'), 'teacher_id');
        $subjectDemand = [];
        $fixedDemand = [];
        foreach ($effective as $rows) {
            foreach ($rows as $r) {
                if ($r['teacher'] !== null) {
                    $fixedDemand[$r['teacher']] = ($fixedDemand[$r['teacher']] ?? 0) + $r['hours'];
                } else {
                    $subjectDemand[$r['subject']] = ($subjectDemand[$r['subject']] ?? 0) + $r['hours'];
                }
            }
        }

        return $teachers->map(fn (Teacher $t) => [
            'id' => $t->id, 'name' => $t->full_name, 'employment_type' => $t->employment_type,
            'subjects' => $t->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'max_weekly_hours' => $t->max_weekly_hours, 'target_weekly_hours' => $t->target_weekly_hours, 'in_timetable' => (bool) $t->in_timetable,
            'current_load' => (int) ($load[$t->id] ?? 0),
            'subject_demand' => array_sum(array_map(fn ($s) => $subjectDemand[$s->id] ?? 0, $t->subjects->all())),
            'fixed_demand' => (int) ($fixedDemand[$t->id] ?? 0),
        ])->values()->all();
    }

    /** Öğretmen saatleri ve bota dahil/hariç (toplu kayıt). */
    public function updateTeachers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'teachers' => ['required', 'array', 'min:1', 'max:300'],
            'teachers.*.id' => ['required', 'integer'],
            'teachers.*.max_weekly_hours' => ['nullable', 'integer', 'min:0', 'max:60'],
            'teachers.*.target_weekly_hours' => ['nullable', 'integer', 'min:0', 'max:60'],
            'teachers.*.in_timetable' => ['required', 'boolean'],
        ], ['teachers.*.max_weekly_hours.max' => 'Haftalık üst sınır en fazla 60 saat olabilir.', 'teachers.*.target_weekly_hours.max' => 'Hedef en fazla 60 saat olabilir.']);

        $models = Teacher::query()->whereIn('id', array_column($data['teachers'], 'id'))->get()->keyBy('id');
        $changes = [];
        DB::transaction(function () use ($data, $models, &$changes) {
            foreach ($data['teachers'] as $row) {
                $t = $models[$row['id']] ?? null;
                if (! $t) {
                    continue;
                }
                $max = isset($row['max_weekly_hours']) ? (int) $row['max_weekly_hours'] : null;
                $target = isset($row['target_weekly_hours']) && (int) $row['target_weekly_hours'] > 0 ? (int) $row['target_weekly_hours'] : null;
                if ($target !== null && $max !== null && $target > $max) {
                    throw new \App\Exceptions\BusinessRuleException("{$t->full_name}: hedef saat ({$target}) üst sınırdan ({$max}) büyük olamaz.", 'teacher_target_over_max');
                }
                $values = ['max_weekly_hours' => $max, 'target_weekly_hours' => $target, 'in_timetable' => (bool) $row['in_timetable']];
                $before = ['max_weekly_hours' => $t->max_weekly_hours, 'target_weekly_hours' => $t->target_weekly_hours, 'in_timetable' => (bool) $t->in_timetable];
                if ($before != $values) {
                    $t->forceFill($values)->save();
                    $changes[] = ['name' => $t->full_name, 'before' => $before, 'after' => $values];
                }
            }
            if ($changes) {
                Audit::log('timetable.teacher_hours_updated', 'Program botu öğretmen saatlerini güncelledi: '.implode(', ', array_map(fn ($c) => $c['name'].' (üst '.($c['after']['max_weekly_hours'] ?? '—').', hedef '.($c['after']['target_weekly_hours'] ?? '—').($c['after']['in_timetable'] ? '' : ', hariç').')', $changes)).'.',
                    null, ['after' => $changes]);
            }
        });

        return $this->ok($changes ? count($changes).' öğretmenin saat ayarı kaydedildi.' : 'Değişiklik yok.', ['changed' => count($changes)]);
    }

    public function index(): JsonResponse
    {
        $runs = TimetableRun::query()->with('creator:id,name')->latest('id')->limit(20)
            ->get(['id', 'academic_term_id', 'class_group_ids', 'status', 'progress', 'penalty', 'quality', 'required_count', 'placed_count', 'unplaced_count', 'hard_violations', 'duration_ms', 'apply_from', 'created_by', 'created_at', 'finished_at', 'applied_at', 'rolled_back_at']);
        $names = ClassGroup::query()->withTrashed()->whereIn('id', $runs->pluck('class_group_ids')->flatten()->unique())->pluck('name', 'id');

        return response()->json(['data' => $runs->map(fn ($r) => $this->summary($r, $names))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
            'class_group_ids' => ['required', 'array', 'min:1', 'max:200'],
            'class_group_ids.*' => ['integer'],
            'settings' => ['nullable', 'array'],
            'settings.weights' => ['nullable', 'array'],
            'settings.weights.*' => ['numeric', 'min:0', 'max:50'],
            'settings.max_per_day' => ['nullable', 'integer', 'min:1', 'max:6'],
            'settings.time_limit' => ['nullable', 'integer', 'min:5', 'max:60'],
            'settings.seed' => ['nullable', 'integer'],
        ], ['class_group_ids.required' => 'En az bir sınıf seçin.']);

        $run = $this->service->queue($data['academic_term_id'], $data['class_group_ids'], $data['settings'] ?? [], $request->user()?->id);

        return response()->json(['message' => 'Program botu sıraya alındı.', 'id' => $run->id], 201);
    }

    public function show(TimetableRun $run): JsonResponse
    {
        $names = ClassGroup::query()->withTrashed()->whereIn('id', $run->class_group_ids)->pluck('name', 'id');
        $ready = in_array($run->status, ['completed', 'applied', 'rolled_back', 'discarded'], true) && $run->result;

        return response()->json([
            'run' => $this->summary($run->loadMissing('creator:id,name'), $names) + [
                'settings' => $run->settings, 'log' => $run->log ?? [], 'error' => $run->error, 'started_at' => $run->started_at?->toIso8601String(),
                'snapshot' => $run->snapshot ? ['created' => count($run->snapshot['new_ids'] ?? []), 'ended' => count($run->snapshot['old'] ?? []), 'removed_sessions' => $run->snapshot['removed_sessions'] ?? 0] : null,
            ],
            'preview' => $ready ? $this->service->preview($run) : null,
        ]);
    }

    public function apply(Request $request, TimetableRun $run): JsonResponse
    {
        $data = $request->validate(['apply_from' => ['required', 'date', 'after:today']], ['apply_from.after' => 'Uygulama tarihi en erken yarın olabilir.']);
        $r = $this->service->apply($run, CarbonImmutable::parse($data['apply_from']), $request->user()?->id);

        return $this->ok("Program uygulandı: {$r['created']} ders saati yazıldı, {$r['ended']} eski şablon sonlandırıldı, {$r['generated_sessions']} oturum üretildi.", $r);
    }

    public function rollback(TimetableRun $run): JsonResponse
    {
        $r = $this->service->rollback($run);

        return $this->ok("Geri alındı: {$r['restored']} eski şablon geri yüklendi, {$r['generated_sessions']} oturum yeniden üretildi.", $r);
    }

    public function discard(TimetableRun $run): JsonResponse
    {
        $this->service->discard($run);

        return $this->ok('Öneriden vazgeçildi.');
    }

    private function summary(TimetableRun $r, $names): array
    {
        return [
            'id' => $r->id, 'status' => $r->status, 'status_label' => TimetableRun::STATUS_LABELS[$r->status] ?? $r->status, 'progress' => (int) $r->progress,
            'academic_term_id' => $r->academic_term_id, 'class_group_ids' => $r->class_group_ids,
            'classes' => collect($r->class_group_ids)->map(fn ($id) => $names[$id] ?? "#$id")->values(),
            'penalty' => $r->penalty, 'quality' => $r->quality, 'required' => (int) $r->required_count, 'placed' => (int) $r->placed_count, 'unplaced' => (int) $r->unplaced_count,
            'hard_violations' => (int) $r->hard_violations, 'duration_ms' => $r->duration_ms, 'apply_from' => $r->apply_from?->toDateString(),
            'created_by' => $r->creator?->name, 'created_at' => $r->created_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
            'applied_at' => $r->applied_at?->toIso8601String(), 'rolled_back_at' => $r->rolled_back_at?->toIso8601String(),
        ];
    }
}
