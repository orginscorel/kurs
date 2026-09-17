<?php

namespace App\Services\Placement;

use App\Events\StudentStatusChanged;
use App\Exceptions\BusinessRuleException;
use App\Models\AcademicTerm;
use App\Models\ActivityFeed;
use App\Models\PlacementRun;
use App\Services\Placement\Core\PromotionPlanner;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dönem sonu seviye atlatma: kaynak dönemin aktif öğrencileri hedef dönemde bir üst seviyeye geçer
 * (şube harfi korunur), 12. sınıflar mezun olur. Önizleme + uygula + geri al.
 */
class PromotionService
{
    public function __construct(
        private readonly ClassStructure $structure,
        private readonly MembershipWriter $writer,
    ) {}

    public function plan(AcademicTerm $from, AcademicTerm $to): array
    {
        if ($from->id === $to->id) {
            throw new BusinessRuleException('Seviye atlatma için farklı bir hedef dönem seçin.', 'promotion_same_term');
        }
        if (CarbonImmutable::parse($to->starts_on)->lte(CarbonImmutable::parse($from->starts_on))) {
            throw new BusinessRuleException('Hedef dönem, kaynak dönemden sonra başlamalı.', 'promotion_term_order');
        }
        $already = PlacementRun::query()->where('kind', 'promotion')->whereNull('reverted_at')
            ->where(fn ($q) => $q->where('academic_term_id', $to->id)->orWhere('summary->from_term_id', $from->id))->exists();
        if ($already) {
            throw new BusinessRuleException('Bu dönemler için seviye atlatma zaten uygulanmış. Tekrar uygulamak öğrencileri iki seviye atlatır.', 'promotion_already_applied');
        }

        $settings = $this->structure->settings();
        // Mezun seviyesi (13) seviye atlatmaya girmez: 12. sınıflar mezun olur.
        $settings['levels'] = array_values(array_filter($settings['levels'], fn ($l) => $l < ClassStructure::GRADUATE));
        if ($settings['levels'] === []) {
            throw new BusinessRuleException('Sınıf yapısında seviye atlatılacak okul seviyesi yok.', 'promotion_no_levels');
        }
        $fromGroups = $this->structure->groups($from->id);
        $toGroups = $this->structure->groups($to->id);
        $gradeValues = array_merge(...array_map(fn ($l) => ClassStructure::gradeValues($l), $settings['levels']));

        $memberships = DB::table('class_group_student')->whereNull('left_on')->whereIn('class_group_id', $fromGroups->pluck('id'))->get(['student_id', 'class_group_id'])->keyBy('student_id');
        $students = DB::table('students')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('status', 'active')->whereIn('school_grade', $gradeValues))->orWhereIn('id', $memberships->keys()->all() ?: [0]))
            ->orderBy('id')->get(['id', 'full_name', 'status', 'school_grade']);

        $groupById = $fromGroups->keyBy('id');
        $input = $students->map(function ($s) use ($memberships, $groupById) {
            $m = $memberships[$s->id] ?? null;
            $g = $m ? $groupById[$m->class_group_id] : null;

            return ['id' => (int) $s->id, 'grade' => $g?->grade_level ?? ClassStructure::gradeOf($s->school_grade), 'section' => $g?->section, 'status' => $s->status];
        })->values()->all();

        $capacities = [];
        $existing = [];
        foreach ($settings['levels'] as $level) {
            foreach ($this->structure->sectionsFor($level) as $section) {
                $key = ClassStructure::key($level, $section);
                $capacities[$key] = isset($toGroups[$key]) ? $toGroups[$key]->capacity : ($this->structure->spec($level, $section)['capacity'] ?? $settings['capacity']);
                $existing[$key] = isset($toGroups[$key]) ? DB::table('class_group_student')->where('class_group_id', $toGroups[$key]->id)->whereNull('left_on')->count() : 0;
            }
        }
        $plan = (new PromotionPlanner)->plan($input, $settings['levels'], $capacities, array_filter($existing));
        $names = $students->pluck('full_name', 'id');
        $oldClass = fn (int $id) => isset($memberships[$id]) ? $groupById[$memberships[$id]->class_group_id]->name : null;

        $byLevel = [];
        foreach ($plan['promote'] as $p) {
            $byLevel[$p['from']] ??= ['from' => $p['from'], 'to' => (string) $p['to'], 'count' => 0, 'unplaced' => 0];
            $byLevel[$p['from']]['count']++;
            $byLevel[$p['from']]['unplaced'] += $p['section'] === null ? 1 : 0;
        }
        ksort($byLevel);
        $maxLevel = max($settings['levels']);

        return [
            'from' => ['id' => $from->id, 'name' => $from->name, 'ends_on' => $from->ends_on?->toDateString()],
            'to' => ['id' => $to->id, 'name' => $to->name, 'starts_on' => $to->starts_on?->toDateString()],
            'levels' => array_values($byLevel),
            'graduates' => ['level' => $maxLevel, 'count' => count($plan['graduate']),
                'students' => array_map(fn ($id) => ['id' => $id, 'full_name' => $names[$id], 'class' => $oldClass($id)], $plan['graduate'])],
            'overflow' => array_map(fn ($o) => ['id' => $o['id'], 'full_name' => $names[$o['id']], 'class' => $o['class']], $plan['overflow']),
            'skipped' => ['count' => count($plan['skipped']), 'students' => array_slice(array_map(fn ($s) => ['id' => $s['id'], 'full_name' => $names[$s['id']], 'reason' => $s['reason']], $plan['skipped']), 0, 50)],
            'targets' => $plan['targets'],
            'missing_classes' => $this->structure->missing($to->id),
            'hash' => sha1(json_encode([$from->id, $to->id, $plan])),
            '_plan' => $plan,
            '_memberships' => $memberships->map(fn ($m) => (int) $m->class_group_id)->all(),
            '_levels' => $settings['levels'],
        ];
    }

    public function preview(AcademicTerm $from, AcademicTerm $to): array
    {
        return array_diff_key($this->plan($from, $to), array_flip(['_plan', '_memberships', '_levels']));
    }

    public function apply(AcademicTerm $from, AcademicTerm $to, string $hash): PlacementRun
    {
        return DB::transaction(function () use ($from, $to, $hash) {
            DB::table('class_groups')->whereIn('academic_term_id', [$from->id, $to->id])->lockForUpdate()->get(['id']);
            $data = $this->plan($from, $to);
            if (! hash_equals($data['hash'], $hash)) {
                throw new BusinessRuleException('Önizlemeden sonra öğrenci ya da sınıf bilgileri değişti. Önizlemeyi yenileyin.', 'placement_stale', [], 409);
            }
            $plan = $data['_plan'];
            if (! $plan['promote'] && ! $plan['graduate']) {
                throw new BusinessRuleException('Seviye atlatılacak aktif öğrenci yok.', 'promotion_empty');
            }

            $cs = new ChangeSet;
            $ensure = $this->structure->ensure($to);
            foreach ($ensure['created'] as $name) {
                // Geri almada yeni açılan boş sınıflar pasife alınır
                foreach ($ensure['group_ids'] as $key => $gid) {
                    [$lv, $sec] = explode('-', $key, 2);
                    if (ClassStructure::className((int) $lv, $sec) === $name) {
                        $cs->groups[$gid] = ['is_active' => false];
                    }
                }
            }
            $toGroups = $this->structure->groups($to->id);

            $run = PlacementRun::query()->create(['academic_term_id' => $to->id, 'kind' => 'promotion', 'grade_levels' => $data['_levels'], 'summary' => [], 'snapshot' => [], 'applied_by' => Auth::id()]);
            $today = CarbonImmutable::today();
            $closeOn = ($from->ends_on && CarbonImmutable::parse($from->ends_on)->lt($today) ? CarbonImmutable::parse($from->ends_on) : $today)->toDateString();
            $joinOn = CarbonImmutable::parse($to->starts_on)->toDateString();
            $students = DB::table('students')->whereIn('id', array_merge(array_column($plan['promote'], 'id'), $plan['graduate']))->get(['id', 'status', 'school_grade'])->keyBy('id');

            foreach ($plan['promote'] as $p) {
                $s = $students[$p['id']];
                $newGrade = (string) $p['to'];
                $cs->recordStudent($p['id'], ['school_grade' => $s->school_grade], ['school_grade' => $newGrade]);
                DB::table('students')->where('id', $p['id'])->update(['school_grade' => $newGrade, 'updated_at' => now()]);
                $old = $this->writer->move($p['id'], $from->id, null, $closeOn, 'Seviye atlatma', 'promotion', $run->id, $cs, false, false, false);
                $newGroupId = null;
                $target = $p['section'] !== null ? ($toGroups[ClassStructure::key($p['to'], $p['section'])] ?? null) : null;
                if ($target !== null) {
                    $newGroupId = $target->id;
                    $this->writer->move($p['id'], $to->id, $newGroupId, $joinOn, "Seviye atlatma: {$p['from']} → {$p['to']}", 'promotion', $run->id, $cs, false, true, false);
                }
                $this->writer->queueEvent($p['id'], $old['from'], $newGroupId, 'promotion', $joinOn, "Seviye atlatma: {$p['from']} → {$p['to']}");
            }

            foreach ($plan['graduate'] as $id) {
                $s = $students[$id];
                $cs->recordStudent($id, ['status' => $s->status], ['status' => 'graduated']);
                DB::table('students')->where('id', $id)->update(['status' => 'graduated', 'updated_at' => now()]);
                $this->writer->move($id, $from->id, null, $closeOn, 'Mezun oldu', 'promotion', $run->id, $cs, false, false, false);
                $enrollments = DB::table('enrollments')->where('student_id', $id)->where('status', 'active')->whereNull('deleted_at')->get(['id', 'class_group_id', 'status', 'ended_on']);
                foreach ($enrollments as $e) {
                    $this->writer->recordEnrollment($cs, $e);
                }
                if ($enrollments->isNotEmpty()) {
                    DB::table('enrollments')->whereIn('id', $enrollments->pluck('id'))->update(['status' => 'completed', 'ended_on' => $closeOn, 'updated_at' => now()]);
                }
                DB::afterCommit(fn () => event(new StudentStatusChanged($id, $s->status, 'graduated')));
            }

            $summary = [
                'from_term_id' => $from->id, 'from_term' => $from->name, 'to_term' => $to->name,
                'promoted' => count($plan['promote']), 'graduated' => count($plan['graduate']), 'unplaced' => count(array_filter($plan['promote'], fn ($p) => $p['section'] === null)),
                'skipped' => count($plan['skipped']), 'classes_created' => $ensure['created'],
            ];
            $run->forceFill(['summary' => $summary, 'snapshot' => $cs->toArray()])->save();

            $text = sprintf('%s → %s seviye atlatma uyguladı: %d öğrenci üst sınıfa geçti, %d öğrenci mezun oldu.', $from->name, $to->name, $summary['promoted'], $summary['graduated']);
            Audit::log('placement.promotion_applied', $text." (işlem #{$run->id})");
            ActivityFeed::query()->create(['kind' => 'placement', 'message' => ucfirst($text), 'meta' => ['placement_run_id' => $run->id], 'occurred_at' => now()]);
            $this->writer->flushEvents();

            return $run;
        });
    }
}
