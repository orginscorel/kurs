<?php

namespace App\Services\Reports;

use App\Models\Exam;
use App\Models\Lead;
use App\Models\Student;
use App\Services\Crm\CrmReportService;
use App\Support\BranchContext;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapor merkezi: salt okunur toplamalar. Her sorgu şubeye açıkça bağlanır (DB::table global scope taşımaz).
 * Ekran ve Excel/PDF aynı metotları kullanır; böylece dışa aktarılan dosya ekranda görülenle birebir aynıdır.
 */
class ReportService
{
    public const ABSENCE_STATUSES = ['absent', 'late', 'excused', 'medical'];

    private function branch(): int
    {
        return app(BranchContext::class)->require();
    }

    /* ------------------------------------------------------------------ Öğrenciler */

    /**
     * @param array{status?:?string, program_id?:?int, class_group_id?:?int, school_grade?:?string, from?:?string, to?:?string, q?:?string} $f
     */
    public function studentQuery(array $f): Builder
    {
        $b = $this->branch();
        $current = DB::table('class_group_student as cgs')
            ->join('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
            ->leftJoin('programs as p', 'p.id', '=', 'cg.program_id')
            ->whereNull('cgs.left_on')->whereNull('cg.deleted_at')
            ->groupBy('cgs.student_id')
            ->selectRaw("cgs.student_id, GROUP_CONCAT(DISTINCT cg.name ORDER BY cg.name SEPARATOR ', ') AS class_names, GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS program_names");

        return DB::table('students as s')
            ->leftJoinSub($current, 'cur', 'cur.student_id', '=', 's.id')
            ->where('s.branch_id', $b)->whereNull('s.deleted_at')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('s.status', $v))
            ->when($f['school_grade'] ?? null, fn ($q, $v) => $q->where('s.school_grade', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('s.registered_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('s.registered_on', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('s.full_name', 'like', '%'.$v.'%')->orWhere('s.student_no', 'like', '%'.$v.'%')))
            ->when($f['class_group_id'] ?? null, fn ($q, $v) => $q->whereExists(fn ($e) => $e->from('class_group_student as x')
                ->whereColumn('x.student_id', 's.id')->where('x.class_group_id', $v)->whereNull('x.left_on')))
            ->when($f['program_id'] ?? null, fn ($q, $v) => $q->whereExists(fn ($e) => $e->from('class_group_student as x')
                ->join('class_groups as y', 'y.id', '=', 'x.class_group_id')
                ->whereColumn('x.student_id', 's.id')->where('y.program_id', $v)->whereNull('x.left_on')))
            ->select(['s.id', 's.student_no', 's.full_name', 's.status', 's.school_name', 's.school_grade', 's.field', 's.phone', 's.registered_on', 's.gender', 'cur.class_names', 'cur.program_names']);
    }

    public function studentSummary(array $f): array
    {
        $base = $this->studentQuery($f);
        $byStatus = DB::query()->fromSub($base, 't')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get()
            ->map(fn ($r) => ['key' => $r->status, 'label' => Student::STATUSES[$r->status] ?? $r->status, 'total' => (int) $r->total])
            ->sortByDesc('total')->values();
        $byGrade = DB::query()->fromSub($base, 't')->selectRaw("COALESCE(NULLIF(school_grade, ''), '—') AS label, COUNT(*) AS total")->groupBy('label')->orderBy('label')->get()
            ->map(fn ($r) => ['label' => $r->label === '—' ? 'Belirtilmemiş' : $r->label, 'total' => (int) $r->total])->values();
        $withoutClass = DB::query()->fromSub($base, 't')->whereNull('class_names')->count();

        return [
            'total' => $byStatus->sum('total'),
            'by_status' => $byStatus,
            'by_grade' => $byGrade,
            'without_class' => $withoutClass,
        ];
    }

    public function studentRow(object $r, bool $sensitive): array
    {
        return [
            'id' => $r->id, 'student_no' => $r->student_no, 'full_name' => $r->full_name,
            'status' => $r->status, 'status_label' => Student::STATUSES[$r->status] ?? $r->status,
            'class_names' => $r->class_names, 'program_names' => $r->program_names,
            'school_name' => $r->school_name, 'school_grade' => $r->school_grade, 'field' => $r->field,
            'phone' => $sensitive ? $r->phone : Sensitive::maskPhone($r->phone),
            'registered_on' => $r->registered_on,
        ];
    }

    /* ------------------------------------------------------------------ Yoklama / devamsızlık */

    /** @param array{from:string, to:string, class_group_id?:?int} $f */
    private function attendanceBase(array $f): Builder
    {
        return DB::table('attendances as a')
            ->join('lesson_sessions as ls', 'ls.id', '=', 'a.lesson_session_id')
            ->join('students as st', 'st.id', '=', 'a.student_id')
            ->where('a.branch_id', $this->branch())
            ->whereBetween('a.date', [$f['from'], $f['to']])
            ->when($f['class_group_id'] ?? null, fn ($q, $v) => $q->where('ls.class_group_id', $v));
    }

    private const COUNTS = 'COUNT(*) AS records, SUM(a.status = "present") AS present, SUM(a.status = "absent") AS absent,
        SUM(a.status = "late") AS late, SUM(a.status = "excused") AS excused, SUM(a.status = "medical") AS medical';

    private static function counts(object $r): array
    {
        $records = (int) $r->records;
        $present = (int) $r->present;
        $late = (int) $r->late;

        return [
            'records' => $records, 'present' => $present, 'absent' => (int) $r->absent, 'late' => $late,
            'excused' => (int) $r->excused, 'medical' => (int) $r->medical,
            // Katılım oranı: var + geç / toplam (izinli ve raporlu da "gelmedi" sayılır; ekran açıklamasında belirtilir)
            'rate' => $records > 0 ? round(($present + $late) * 100 / $records, 1) : null,
        ];
    }

    public function attendance(array $f): array
    {
        $b = $this->branch();
        $totals = $this->attendanceBase($f)->selectRaw(self::COUNTS)->first();

        $sessions = DB::table('lesson_sessions')->where('branch_id', $b)->whereBetween('date', [$f['from'], $f['to']])
            ->when($f['class_group_id'] ?? null, fn ($q, $v) => $q->where('class_group_id', $v))
            ->selectRaw('COUNT(*) AS total, SUM(status = "cancelled") AS cancelled, SUM(status <> "cancelled" AND attendance_taken_at IS NOT NULL) AS taken,
                SUM(status <> "cancelled" AND attendance_taken_at IS NULL AND date <= ?) AS missing', [CarbonImmutable::today()->toDateString()])
            ->first();

        $byDay = $this->attendanceBase($f)->selectRaw('a.date AS day, '.self::COUNTS)->groupBy('a.date')->orderBy('a.date')->get()
            ->map(fn ($r) => ['date' => (string) $r->day] + self::counts($r))->values();

        $byClass = $this->attendanceBase($f)->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->selectRaw('cg.id, cg.name, COUNT(DISTINCT a.student_id) AS students, '.self::COUNTS)
            ->groupBy('cg.id', 'cg.name')->orderBy('cg.name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'students' => (int) $r->students] + self::counts($r))->values();

        return [
            'from' => $f['from'], 'to' => $f['to'],
            'totals' => self::counts($totals) + [
                'students' => (int) $this->attendanceBase($f)->distinct()->count('a.student_id'),
                'sessions' => (int) ($sessions->total ?? 0), 'cancelled_sessions' => (int) ($sessions->cancelled ?? 0),
                'taken_sessions' => (int) ($sessions->taken ?? 0), 'missing_sessions' => (int) ($sessions->missing ?? 0),
            ],
            'by_day' => $byDay,
            'by_class' => $byClass,
        ];
    }

    /** Öğrenci bazlı devamsızlık (en çok gelmeyen üstte). */
    public function attendanceStudents(array $f, ?int $limit = null): Collection
    {
        $classNames = DB::table('class_group_student as cgs')->join('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
            ->whereNull('cgs.left_on')->groupBy('cgs.student_id')
            ->selectRaw("cgs.student_id, GROUP_CONCAT(DISTINCT cg.name ORDER BY cg.name SEPARATOR ', ') AS class_names");

        return $this->attendanceBase($f)->leftJoinSub($classNames, 'cn', 'cn.student_id', '=', 'a.student_id')
            ->selectRaw('a.student_id, st.full_name, st.student_no, cn.class_names, '.self::COUNTS)
            ->groupBy('a.student_id', 'st.full_name', 'st.student_no', 'cn.class_names')
            ->orderByRaw('SUM(a.status = "absent") DESC, SUM(a.status IN ("excused","medical")) DESC, SUM(a.status = "late") DESC, st.full_name')
            ->when($limit, fn ($q, $l) => $q->limit($l))
            ->get()
            ->map(fn ($r) => ['student_id' => $r->student_id, 'full_name' => $r->full_name, 'student_no' => $r->student_no, 'class_names' => $r->class_names] + self::counts($r))
            ->values();
    }

    /* ------------------------------------------------------------------ Sınavlar */

    public function examOptions(): Collection
    {
        return Exam::query()->with('type:id,name')->orderByDesc('exam_date')->orderByDesc('id')->limit(200)
            ->get(['id', 'name', 'exam_date', 'exam_type_id', 'status', 'participant_count'])
            ->map(fn (Exam $e) => [
                'id' => $e->id, 'name' => $e->name, 'exam_date' => $e->exam_date?->toDateString(),
                'type' => $e->type?->name, 'status' => $e->status, 'participants' => (int) $e->participant_count,
            ])->values();
    }

    private function resultsBase(int $examId, ?int $classGroupId): Builder
    {
        return DB::table('exam_results as r')
            ->join('exams as e', 'e.id', '=', 'r.exam_id')
            ->join('students as st', 'st.id', '=', 'r.student_id')
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'r.class_group_id')
            ->where('e.branch_id', $this->branch())->whereNull('e.deleted_at')
            ->where('r.exam_id', $examId)
            ->when($classGroupId, fn ($q, $v) => $q->where('r.class_group_id', $v));
    }

    public function exam(int $examId, ?int $classGroupId): ?array
    {
        $exam = Exam::query()->with('type:id,name')->find($examId);
        if (! $exam) {
            return null;
        }

        $s = $this->resultsBase($examId, $classGroupId)
            ->selectRaw('COUNT(*) AS n, AVG(r.net) AS avg_net, AVG(r.score) AS avg_score, MAX(r.net) AS max_net, MIN(r.net) AS min_net, MAX(r.score) AS max_score')->first();
        $byClass = $this->resultsBase($examId, null)
            ->selectRaw("COALESCE(cg.name, 'Sınıfsız') AS name, r.class_group_id AS id, COUNT(*) AS n, AVG(r.net) AS avg_net, AVG(r.score) AS avg_score, MAX(r.net) AS max_net")
            ->groupBy('r.class_group_id', 'cg.name')->orderByDesc('avg_net')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'participants' => (int) $r->n, 'avg_net' => self::dec($r->avg_net), 'avg_score' => self::dec($r->avg_score), 'max_net' => self::dec($r->max_net)])
            ->values();

        return [
            'exam' => ['id' => $exam->id, 'name' => $exam->name, 'exam_date' => $exam->exam_date?->toDateString(), 'type' => $exam->type?->name, 'status' => $exam->status],
            'summary' => [
                'participants' => (int) ($s->n ?? 0), 'avg_net' => self::dec($s->avg_net), 'avg_score' => self::dec($s->avg_score),
                'max_net' => self::dec($s->max_net), 'min_net' => self::dec($s->min_net), 'max_score' => self::dec($s->max_score),
            ],
            'by_class' => $byClass,
        ];
    }

    public function examRows(int $examId, ?int $classGroupId): Collection
    {
        return $this->resultsBase($examId, $classGroupId)
            ->orderByRaw('r.score IS NULL, r.score DESC')->orderByDesc('r.net')->orderBy('st.full_name')
            ->get(['r.id', 'st.id as student_id', 'st.full_name', 'st.student_no', 'cg.name as class_name', 'r.correct', 'r.wrong', 'r.blank', 'r.net', 'r.score', 'r.institution_rank', 'r.class_rank'])
            ->map(fn ($r) => [
                'id' => $r->id, 'student_id' => $r->student_id, 'full_name' => $r->full_name, 'student_no' => $r->student_no, 'class_name' => $r->class_name,
                'correct' => (int) $r->correct, 'wrong' => (int) $r->wrong, 'blank' => (int) $r->blank,
                'net' => self::dec($r->net), 'score' => self::dec($r->score), 'institution_rank' => $r->institution_rank, 'class_rank' => $r->class_rank,
            ])->values();
    }

    private static function dec(mixed $v, int $digits = 2): ?float
    {
        return $v === null ? null : round((float) $v, $digits);
    }

    /* ------------------------------------------------------------------ Ön kayıt */

    /** @param array{from?:?string, to?:?string, source?:?string} $f */
    public function leads(array $f): array
    {
        $report = app(CrmReportService::class)->report($f['from'] ?? null, $f['to'] ?? null, $f['source'] ?? null);

        $bySourceMonth = Lead::query()
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS ym, source, COUNT(*) AS total, SUM(stage = 'won') AS won")
            ->groupBy('ym', 'source')->orderBy('ym')->get()
            ->map(fn ($r) => ['ym' => $r->ym, 'source' => $r->source, 'label' => Lead::SOURCES[$r->source] ?? $r->source, 'total' => (int) $r->total, 'won' => (int) $r->won])
            ->values();

        return $report + ['by_source_month' => $bySourceMonth, 'sources' => Lead::SOURCES];
    }

    /* ------------------------------------------------------------------ Öğretmen ders yükü */

    /** @param array{from:string, to:string} $f */
    public function teacherLoad(array $f): Collection
    {
        $b = $this->branch();
        $today = CarbonImmutable::today()->toDateString();
        $termId = DB::table('academic_terms')->where('branch_id', $b)->where('is_current', true)->value('id');

        $weekly = DB::table('lesson_schedules')->where('branch_id', $b)->whereNull('deleted_at')
            ->when($termId, fn ($q) => $q->where('academic_term_id', $termId))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, SUM(TIME_TO_SEC(TIMEDIFF(ends_at, starts_at))) / 60 AS minutes, COUNT(*) AS slots, COUNT(DISTINCT class_group_id) AS classes')
            ->get()->keyBy('teacher_id');

        $sessions = DB::table('lesson_sessions')->where('branch_id', $b)->whereBetween('date', [$f['from'], $f['to']])
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, COUNT(*) AS total, SUM(status = "cancelled") AS cancelled,
                SUM(CASE WHEN status <> "cancelled" THEN TIMESTAMPDIFF(MINUTE, starts_at, ends_at) ELSE 0 END) AS minutes,
                SUM(status <> "cancelled" AND date <= ?) AS due,
                SUM(status <> "cancelled" AND attendance_taken_at IS NOT NULL) AS taken', [$today])
            ->get()->keyBy('teacher_id');

        $studies = DB::table('study_sessions')->where('branch_id', $b)
            ->whereBetween('starts_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])->where('status', '<>', 'cancelled')
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, COUNT(*) AS total, SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) AS minutes')
            ->get()->keyBy('teacher_id');

        $leaves = DB::table('teacher_leaves as l')->join('teachers as t', 't.id', '=', 'l.teacher_id')
            ->where('t.branch_id', $b)->where('l.status', 'approved')
            ->where('l.starts_on', '<=', $f['to'])->where('l.ends_on', '>=', $f['from'])
            ->groupBy('l.teacher_id')
            ->selectRaw('l.teacher_id, SUM(DATEDIFF(LEAST(l.ends_on, ?), GREATEST(l.starts_on, ?)) + 1) AS days', [$f['to'], $f['from']])
            ->get()->keyBy('teacher_id');

        return DB::table('teachers')->where('branch_id', $b)->whereNull('deleted_at')
            ->orderByDesc('is_active')->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'title', 'specialty', 'employment_type', 'max_weekly_hours', 'target_weekly_hours', 'is_active'])
            ->map(function ($t) use ($weekly, $sessions, $studies, $leaves) {
                $w = $weekly->get($t->id);
                $s = $sessions->get($t->id);
                $st = $studies->get($t->id);
                $due = (int) ($s->due ?? 0);
                $taken = (int) ($s->taken ?? 0);

                return [
                    'id' => $t->id, 'name' => trim($t->first_name.' '.$t->last_name), 'specialty' => $t->specialty, 'is_active' => (bool) $t->is_active,
                    'employment_type' => $t->employment_type,
                    'weekly_hours' => round(((float) ($w->minutes ?? 0)) / 60, 1),
                    'weekly_slots' => (int) ($w->slots ?? 0), 'classes' => (int) ($w->classes ?? 0),
                    'max_weekly_hours' => $t->max_weekly_hours !== null ? (float) $t->max_weekly_hours : null,
                    'target_weekly_hours' => $t->target_weekly_hours !== null ? (float) $t->target_weekly_hours : null,
                    'sessions' => (int) ($s->total ?? 0), 'cancelled' => (int) ($s->cancelled ?? 0),
                    'lesson_hours' => round(((float) ($s->minutes ?? 0)) / 60, 1),
                    'attendance_due' => $due, 'attendance_taken' => $taken,
                    'attendance_missing' => max(0, $due - $taken),
                    'studies' => (int) ($st->total ?? 0), 'study_hours' => round(((float) ($st->minutes ?? 0)) / 60, 1),
                    'leave_days' => (int) ($leaves->get($t->id)->days ?? 0),
                ];
            })->values();
    }
}
