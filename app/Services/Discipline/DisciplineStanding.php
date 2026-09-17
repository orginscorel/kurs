<?php

namespace App\Services\Discipline;

use App\Models\AcademicTerm;
use App\Support\BranchContext;
use App\Support\Discipline\DisciplineCatalog as C;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Öğrencinin dönemlik disiplin durumu: ceza puanı, olumlu puan, net puan, uyarı seviyesi, süren yaptırımlar.
 * Toplu sorgu ile çalışır (liste/pano/risk için N+1 yok). Asılsız ("unfounded") olaylar ve silinen olaylar sayılmaz.
 */
class DisciplineStanding
{
    private static ?bool $ready = null;

    /** Tablolar kurulu mu (risk servisi test şemalarında da çalışsın diye). */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('discipline_incidents') && Schema::hasTable('discipline_incident_students');
    }

    /** @return array{from:string, to:string, name:string} */
    public static function termRange(?int $branchId = null, ?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::today();
        $branchId ??= app(BranchContext::class)->id();
        $term = DB::table('academic_terms')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('starts_on', '<=', $on->toDateString())->where('ends_on', '>=', $on->toDateString())
            ->orderByDesc('is_current')->first(['name', 'starts_on', 'ends_on'])
            ?? DB::table('academic_terms')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->where('is_current', true)->first(['name', 'starts_on', 'ends_on']);

        if ($term) {
            return ['from' => (string) $term->starts_on, 'to' => (string) $term->ends_on, 'name' => (string) $term->name];
        }
        // Dönem tanımlı değilse eğitim yılı: Eylül – Ağustos
        $startYear = $on->month >= 9 ? $on->year : $on->year - 1;

        return ['from' => "{$startYear}-09-01", 'to' => ($startYear + 1).'-08-31', 'name' => "{$startYear}-".($startYear + 1)];
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array{penalty:int, merit:int, net:int, level:string, level_label:string, incidents:int, positives:int}>
     */
    public function forStudents(array $studentIds, ?array $range = null, ?array $settings = null): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if ($studentIds === [] || ! self::ready()) {
            return [];
        }
        $range ??= self::termRange();
        $settings ??= DisciplineSettings::all();

        $rows = DB::table('discipline_incident_students as dis')
            ->join('discipline_incidents as di', 'di.id', '=', 'dis.incident_id')
            ->whereIn('dis.student_id', $studentIds)
            ->whereNull('di.deleted_at')
            ->where(fn ($q) => $q->whereNull('di.outcome')->orWhere('di.outcome', '!=', 'unfounded'))
            ->whereBetween('di.occurred_at', [$range['from'].' 00:00:00', $range['to'].' 23:59:59'])
            ->groupBy('dis.student_id')
            ->selectRaw("dis.student_id, SUM(dis.penalty_points) AS penalty, SUM(dis.merit_points) AS merit,
                SUM(CASE WHEN dis.role = 'involved' AND di.kind = 'negative' THEN 1 ELSE 0 END) AS incidents,
                SUM(CASE WHEN di.kind = 'positive' THEN 1 ELSE 0 END) AS positives")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $penalty = (int) $r->penalty;
            $merit = (int) $r->merit;
            $net = DisciplineRules::netPoints($penalty, $merit, (bool) $settings['merit_offsets_penalty']);
            $level = DisciplineRules::level($net, $settings);
            $out[(int) $r->student_id] = [
                'penalty' => $penalty, 'merit' => $merit, 'net' => $net, 'level' => $level, 'level_label' => C::LEVELS[$level],
                'incidents' => (int) $r->incidents, 'positives' => (int) $r->positives,
            ];
        }

        return $out;
    }

    public function forStudent(int $studentId): array
    {
        $range = self::termRange();

        return ($this->forStudents([$studentId], $range)[$studentId] ?? [
            'penalty' => 0, 'merit' => 0, 'net' => 0, 'level' => 'none', 'level_label' => C::LEVELS['none'], 'incidents' => 0, 'positives' => 0,
        ]) + ['term' => $range];
    }

    /**
     * Dikkat gerektiren öğrenciler (seviye >= uyarı), en yüksek net puan üstte. Düz dizi döner (önbelleğe uygun).
     *
     * @return list<array{student_id:int, full_name:string, student_no:string, net:int, penalty:int, merit:int, level:string, level_label:string, incidents:int}>
     */
    public function attentionList(int $branchId, int $limit = 50, string $minLevel = 'warning'): array
    {
        if (! self::ready()) {
            return [];
        }
        $range = self::termRange($branchId);
        $settings = DisciplineSettings::all($branchId);
        $min = $minLevel === 'watch' ? (int) $settings['threshold_watch'] : (int) $settings['threshold_warning'];

        $candidates = DB::table('discipline_incident_students as dis')
            ->join('discipline_incidents as di', 'di.id', '=', 'dis.incident_id')
            ->join('students as s', 's.id', '=', 'dis.student_id')
            ->where('di.branch_id', $branchId)->whereNull('di.deleted_at')->whereNull('s.deleted_at')
            ->whereNotIn('s.status', ['withdrawn', 'graduated'])
            ->where(fn ($q) => $q->whereNull('di.outcome')->orWhere('di.outcome', '!=', 'unfounded'))
            ->whereBetween('di.occurred_at', [$range['from'].' 00:00:00', $range['to'].' 23:59:59'])
            ->groupBy('dis.student_id')
            ->havingRaw('SUM(dis.penalty_points) >= ?', [$min])
            ->pluck('dis.student_id')->map(fn ($v) => (int) $v)->all();

        if ($candidates === []) {
            return [];
        }
        $standing = $this->forStudents($candidates, $range, $settings);
        $names = DB::table('students')->whereIn('id', array_keys($standing))->get(['id', 'full_name', 'student_no'])->keyBy('id');
        $rank = ['none' => 0, 'watch' => 1, 'warning' => 2, 'critical' => 3];

        return collect($standing)
            ->filter(fn ($s) => $rank[$s['level']] >= $rank[$minLevel])
            ->map(fn ($s, $id) => ['student_id' => (int) $id, 'full_name' => (string) ($names[$id]->full_name ?? ''), 'student_no' => (string) ($names[$id]->student_no ?? '')] + $s)
            ->sortByDesc('net')->take($limit)->values()->all();
    }
}
