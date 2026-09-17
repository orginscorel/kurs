<?php

namespace App\Services\Reports;

use App\Services\Discipline\DisciplineRules;
use App\Services\Discipline\DisciplineSettings;
use App\Support\BranchContext;
use App\Support\Discipline\DisciplineCatalog as C;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rapor merkezi › Disiplin. Salt okunur toplamalar; ekran, Excel ve PDF aynı metodu kullanır.
 * Asılsız kapatılan ve silinen olaylar sayılmaz. İfadeler taşınabilir (MariaDB + test SQLite).
 */
class DisciplineReportService
{
    public const MONTHS = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    private function branch(): int
    {
        return app(BranchContext::class)->require();
    }

    /**
     * Olaya karışan öğrenci satırları (rol = involved) + olay.
     *
     * @param array{from:string, to:string, class_group_id?:?int, behavior_id?:?int, category?:?string, teacher_id?:?int} $f
     */
    private function participants(array $f): Builder
    {
        return DB::table('discipline_incident_students as p')
            ->join('discipline_incidents as i', 'i.id', '=', 'p.incident_id')
            ->leftJoin('discipline_behaviors as b', 'b.id', '=', 'p.behavior_id')
            ->where('i.branch_id', $this->branch())
            ->whereNull('i.deleted_at')
            ->where(fn ($q) => $q->whereNull('i.outcome')->orWhere('i.outcome', '!=', 'unfounded'))
            ->whereBetween('i.occurred_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->where('p.role', 'involved')
            ->when($f['class_group_id'] ?? null, fn ($q, $v) => $q->whereExists(fn ($e) => $e->from('class_group_student as x')
                ->whereColumn('x.student_id', 'p.student_id')->where('x.class_group_id', $v)->whereNull('x.left_on')))
            ->when($f['behavior_id'] ?? null, fn ($q, $v) => $q->where('p.behavior_id', $v))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('b.category', $v))
            ->when($f['teacher_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('i.teacher_id', $v)
                ->orWhereIn('i.reported_by', DB::table('teachers')->where('id', $v)->whereNotNull('user_id')->select('user_id'))));
    }

    private function sanctions(array $f): Builder
    {
        return DB::table('discipline_sanctions as s')
            ->join('discipline_sanction_types as t', 't.id', '=', 's.sanction_type_id')
            ->whereNull('s.deleted_at')
            ->whereNotIn('s.status', ['cancelled', 'proposed'])
            ->whereExists(fn ($e) => $e->fromSub($this->participants($f)->select('p.incident_id', 'p.student_id'), 'pp')
                ->whereColumn('pp.incident_id', 's.incident_id')->whereColumn('pp.student_id', 's.student_id'));
    }

    /** @param array{from:string, to:string} $f */
    public function report(array $f): array
    {
        $settings = DisciplineSettings::all();
        $neg = "i.kind = 'negative'";
        $pos = "i.kind = 'positive'";

        $t = $this->participants($f)->selectRaw("
            COUNT(DISTINCT CASE WHEN {$neg} THEN i.id END) AS incidents,
            COUNT(DISTINCT CASE WHEN {$pos} THEN i.id END) AS positives,
            COUNT(DISTINCT CASE WHEN {$neg} THEN p.student_id END) AS students,
            COALESCE(SUM(p.penalty_points), 0) AS penalty,
            COALESCE(SUM(p.merit_points), 0) AS merit,
            COUNT(DISTINCT CASE WHEN {$neg} AND i.status IN ('open','review') THEN i.id END) AS open_incidents,
            COUNT(DISTINCT CASE WHEN {$neg} AND i.status = 'appealed' THEN i.id END) AS appealed_incidents
        ")->first();

        $sanctionTotals = $this->sanctions($f)->selectRaw("COUNT(*) AS c, COALESCE(SUM(CASE WHEN t.is_suspension = 1 THEN s.days ELSE 0 END), 0) AS days,
            SUM(CASE WHEN s.status IN ('active','appealed') THEN 1 ELSE 0 END) AS active")->first();

        $byBehavior = $this->participants($f)->whereNotNull('p.behavior_id')
            ->groupBy('p.behavior_id', 'b.name', 'b.category', 'b.kind')
            ->selectRaw('p.behavior_id AS id, b.name, b.category, b.kind, COUNT(*) AS c, COALESCE(SUM(p.penalty_points + p.merit_points), 0) AS pts')
            ->orderByDesc('c')->orderBy('b.name')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'kind' => $r->kind, 'category' => $r->category,
                'category_label' => C::CATEGORIES[$r->category] ?? $r->category, 'count' => (int) $r->c, 'points' => (int) $r->pts])
            ->values()->all();

        $byCategory = collect($byBehavior)->groupBy('category')
            ->map(fn ($rows, $cat) => ['key' => $cat, 'label' => C::CATEGORIES[$cat] ?? $cat, 'kind' => $rows->first()['kind'], 'count' => $rows->sum('count')])
            ->sortByDesc('count')->values()->all();

        $bySeverity = $this->participants($f)->whereRaw($neg)->groupBy('i.severity')
            ->selectRaw('i.severity, COUNT(DISTINCT i.id) AS c')->pluck('c', 'i.severity');

        $byStatus = $this->participants($f)->whereRaw($neg)->groupBy('i.status')
            ->selectRaw('i.status, COUNT(DISTINCT i.id) AS c')->pluck('c', 'i.status');

        $bySanction = $this->sanctions($f)->groupBy('t.id', 't.name', 't.level', 't.tone')
            ->selectRaw("t.id, t.name, t.level, t.tone, COUNT(*) AS c, SUM(CASE WHEN s.status IN ('active','appealed') THEN 1 ELSE 0 END) AS active")
            ->orderBy('t.level')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'level' => (int) $r->level, 'tone' => $r->tone, 'count' => (int) $r->c, 'active' => (int) $r->active])
            ->values()->all();

        // Sınıf: öğrencinin şu anki sınıfı (sınıfsız öğrenciler "Sınıfsız")
        $current = DB::table('class_group_student as cgs')->join('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
            ->whereNull('cgs.left_on')->whereNull('cg.deleted_at')
            ->groupBy('cgs.student_id')->selectRaw('cgs.student_id, MIN(cg.id) AS class_id, MIN(cg.name) AS class_name');
        $sanctionCounts = $this->sanctions($f)->groupBy('s.student_id')->selectRaw('s.student_id, COUNT(*) AS c')->pluck('c', 's.student_id');

        $perStudent = $this->participants($f)->leftJoinSub($current, 'cur', 'cur.student_id', '=', 'p.student_id')
            ->groupBy('p.student_id', 'cur.class_id', 'cur.class_name')
            ->selectRaw("p.student_id, cur.class_id, cur.class_name,
                COUNT(DISTINCT CASE WHEN {$neg} THEN i.id END) AS incidents,
                COUNT(DISTINCT CASE WHEN {$pos} THEN i.id END) AS positives,
                COALESCE(SUM(p.penalty_points), 0) AS penalty, COALESCE(SUM(p.merit_points), 0) AS merit, MAX(i.occurred_at) AS last_at")
            ->get();

        $byClass = $perStudent->groupBy(fn ($r) => $r->class_id ?? 0)->map(fn ($rows, $cid) => [
            'id' => $cid ?: null,
            'name' => $rows->first()->class_name ?? 'Sınıfsız',
            'incidents' => (int) $rows->sum('incidents'),
            'students' => $rows->filter(fn ($r) => $r->incidents > 0)->count(),
            'penalty' => (int) $rows->sum('penalty'),
            'positives' => (int) $rows->sum('positives'),
            'sanctions' => (int) $rows->sum(fn ($r) => $sanctionCounts[$r->student_id] ?? 0),
        ])->sortBy('name', SORT_NATURAL)->values()->all();

        $repeatIds = $perStudent->filter(fn ($r) => $r->incidents >= 2)->pluck('student_id')->all();
        $names = $repeatIds ? DB::table('students')->whereIn('id', $repeatIds)->get(['id', 'full_name', 'student_no'])->keyBy('id') : collect();
        $repeaters = $perStudent->filter(fn ($r) => $r->incidents >= 2)->map(function ($r) use ($names, $settings, $sanctionCounts) {
            $net = DisciplineRules::netPoints((int) $r->penalty, (int) $r->merit, (bool) $settings['merit_offsets_penalty']);
            $level = DisciplineRules::level($net, $settings);

            return [
                'student_id' => (int) $r->student_id, 'full_name' => $names[$r->student_id]->full_name ?? '', 'student_no' => $names[$r->student_id]->student_no ?? '',
                'class_names' => $r->class_name ?? '—', 'incidents' => (int) $r->incidents, 'penalty' => (int) $r->penalty, 'merit' => (int) $r->merit,
                'net' => $net, 'level' => $level, 'level_label' => C::LEVELS[$level], 'sanctions' => (int) ($sanctionCounts[$r->student_id] ?? 0),
                'last_at' => $r->last_at ? CarbonImmutable::parse($r->last_at)->toAtomString() : null,
            ];
        })->sortBy([['net', 'desc'], ['incidents', 'desc']])->values()->all();

        $byTeacher = $this->participants($f)
            ->leftJoin('users as u', 'u.id', '=', 'i.reported_by')
            ->groupBy('i.reported_by', 'u.name')
            ->selectRaw("i.reported_by AS user_id, u.name, COUNT(DISTINCT CASE WHEN {$neg} THEN i.id END) AS incidents, COUNT(DISTINCT CASE WHEN {$pos} THEN i.id END) AS positives")
            ->orderByDesc('incidents')->get()
            ->map(fn ($r) => ['user_id' => $r->user_id ? (int) $r->user_id : null, 'name' => $r->name ?? 'Bilinmiyor', 'incidents' => (int) $r->incidents, 'positives' => (int) $r->positives])
            ->values()->all();

        return [
            'from' => $f['from'], 'to' => $f['to'],
            'totals' => [
                'incidents' => (int) $t->incidents, 'positives' => (int) $t->positives, 'students' => (int) $t->students,
                'penalty' => (int) $t->penalty, 'merit' => (int) $t->merit,
                'open_incidents' => (int) $t->open_incidents, 'appealed_incidents' => (int) $t->appealed_incidents,
                'sanctions' => (int) ($sanctionTotals->c ?? 0), 'active_sanctions' => (int) ($sanctionTotals->active ?? 0),
                'suspension_days' => (int) ($sanctionTotals->days ?? 0), 'repeaters' => count($repeaters),
            ],
            'by_behavior' => $byBehavior,
            'by_category' => $byCategory,
            'by_severity' => collect(C::SEVERITIES)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'count' => (int) ($bySeverity[$k] ?? 0)])->values()->all(),
            'by_status' => collect(C::INCIDENT_STATUSES)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'count' => (int) ($byStatus[$k] ?? 0)])->values()->all(),
            'by_sanction' => $bySanction,
            'by_class' => $byClass,
            'by_teacher' => $byTeacher,
            'repeaters' => $repeaters,
            'trend' => $this->trend($f),
        ];
    }

    /** Aylık olay / olumlu kayıt / yaptırım (aralıktaki her ay, boş aylar dahil; en fazla 24 ay). */
    public function trend(array $f): array
    {
        $rows = $this->participants($f)->groupByRaw('SUBSTR(i.occurred_at, 1, 7)')
            ->selectRaw("SUBSTR(i.occurred_at, 1, 7) AS ym, COUNT(DISTINCT CASE WHEN i.kind = 'negative' THEN i.id END) AS incidents,
                COUNT(DISTINCT CASE WHEN i.kind = 'positive' THEN i.id END) AS positives")
            ->get()->keyBy('ym');
        $sanctions = $this->sanctions($f)->join('discipline_incidents as si', 'si.id', '=', 's.incident_id')
            ->groupByRaw('SUBSTR(si.occurred_at, 1, 7)')->selectRaw('SUBSTR(si.occurred_at, 1, 7) AS ym, COUNT(*) AS c')->pluck('c', 'ym');

        $out = [];
        $cur = CarbonImmutable::parse($f['from'])->startOfMonth();
        $end = CarbonImmutable::parse($f['to'])->startOfMonth();
        for ($i = 0; $cur->lte($end) && $i < 24; $i++, $cur = $cur->addMonth()) {
            $ym = $cur->format('Y-m');
            $out[] = [
                'ym' => $ym, 'label' => self::MONTHS[$cur->month].' '.$cur->format('y'),
                'incidents' => (int) ($rows[$ym]->incidents ?? 0), 'positives' => (int) ($rows[$ym]->positives ?? 0), 'sanctions' => (int) ($sanctions[$ym] ?? 0),
            ];
        }

        return $out;
    }

    /** Olay satırları (Excel "Olaylar" sayfası). @return \Illuminate\Support\Collection<int, object> */
    public function incidentRows(array $f)
    {
        return $this->participants($f)
            ->join('students as st', 'st.id', '=', 'p.student_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.reported_by')
            ->orderBy('i.occurred_at')
            ->get(['i.incident_no', 'i.occurred_at', 'i.kind', 'i.status', 'i.severity', 'i.location', 'st.student_no', 'st.full_name',
                'b.name as behavior', 'b.category', 'p.penalty_points', 'p.merit_points', 'u.name as reporter']);
    }

    /** Yaptırım satırları (Excel "Yaptırımlar" sayfası). */
    public function sanctionRows(array $f)
    {
        return $this->sanctions($f)
            ->join('students as st', 'st.id', '=', 's.student_id')
            ->join('discipline_incidents as si', 'si.id', '=', 's.incident_id')
            ->orderBy('s.decided_at')
            ->get(['s.sanction_no', 'si.incident_no', 'st.student_no', 'st.full_name', 't.name as type', 's.status', 's.decided_at', 's.starts_on', 's.ends_on', 's.days', 's.expires_on']);
    }
}
