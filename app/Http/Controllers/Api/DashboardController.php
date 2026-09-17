<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityFeed;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Yönetici Kontrol Merkezi. Tüm göstergeler toplu SQL ile hesaplanır ve şube başına
 * 30 sn önbelleğe alınır; canlı akış ayrı ve önbelleksiz uçtan gelir.
 */
class DashboardController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $canFinance = $request->user()->can('finance.view');

        $data = Cache::remember("dashboard:{$branchId}", 30, fn () => $this->compute($branchId));

        if (! $canFinance) {
            unset($data['finance'], $data['charts']['finance'], $data['charts']['collection_rate']);
        }
        if (! $request->user()->can('crm.view')) {
            unset($data['attention']['leads_due']);
        }
        if (! $request->user()->can('risk.view')) {
            unset($data['attention']['risk_high']);
        }
        if (! $request->user()->can('discipline.view')) {
            unset($data['attention']['discipline_students'], $data['attention']['discipline_open']);
        }

        return response()->json($data);
    }

    /** Canlı kurum akışı: ?after_id ile yalnız yeni olaylar (her 5 sn yoklanır). */
    public function feed(Request $request): JsonResponse
    {
        $afterId = (int) $request->integer('after_id');
        $canFinance = $request->user()->can('finance.view');

        $items = ActivityFeed::query()
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->when(! $canFinance, fn ($q) => $q->where('kind', '!=', 'payment'))
            ->orderByDesc('id')
            ->limit($afterId > 0 ? 50 : 30)
            ->get(['id', 'kind', 'message', 'student_id', 'subject_type', 'subject_id', 'occurred_at']);

        return response()->json(['data' => $items]);
    }

    private function compute(int $branchId): array
    {
        $now = CarbonImmutable::now();
        $today = $now->toDateString();
        $monthStart = $now->startOfMonth()->toDateTimeString();
        $b = fn (string $table) => DB::table($table)->where("$table.branch_id", $branchId);

        // ---- Öğrenci ve kurum anlık durumu
        $students = $b('students')->whereNull('deleted_at')
            ->selectRaw("COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status IN ('frozen')) AS frozen")
            ->first();

        $expectedToday = DB::table('lesson_sessions as ls')
            ->join('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'ls.class_group_id')->whereNull('cgs.left_on'))
            ->where('ls.branch_id', $branchId)->where('ls.date', $today)->where('ls.status', '!=', 'cancelled')
            ->distinct()->count('cgs.student_id');

        $presence = $b('daily_presences')->where('date', $today)
            ->selectRaw('COUNT(first_entry_at) AS arrived, SUM(is_inside) AS inside')->first();

        $firstLessonStarted = $b('lesson_sessions')->where('date', $today)->where('status', '!=', 'cancelled')->where('starts_at', '<=', $now)->exists();

        $sessions = $b('lesson_sessions')->where('date', $today)->where('status', '!=', 'cancelled')
            ->selectRaw('COUNT(*) AS total, SUM(starts_at <= ? AND ends_at > ?) AS in_progress, SUM(ends_at <= ?) AS done, COUNT(DISTINCT teacher_id) AS teachers', [$now, $now, $now])
            ->first();

        $classrooms = $b('classrooms')->whereNull('deleted_at')->where('is_active', true)->count();
        $occupiedNow = $b('lesson_sessions')->where('date', $today)->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->distinct()->count('classroom_id');

        $absentToday = $b('attendances')->where('date', $today)->where('status', 'absent')->distinct()->count('student_id');
        $lateToday = $b('attendances')->where('date', $today)->where('status', 'late')->distinct()->count('student_id');

        // ---- Finans
        $collectedToday = (string) $b('payments')->whereNull('voided_at')->whereDate('paid_at', $today)->sum('amount');
        $collectedMonth = (string) $b('payments')->whereNull('voided_at')->where('paid_at', '>=', $monthStart)->sum('amount');

        $inst = $b('installments')->whereIn('status', ['pending', 'partial', 'overdue'])
            ->selectRaw("
                SUM(amount - paid_amount) AS receivable,
                SUM(CASE WHEN status = 'overdue' THEN amount - paid_amount ELSE 0 END) AS overdue_amount,
                SUM(status = 'overdue') AS overdue_count,
                SUM(CASE WHEN status != 'overdue' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status != 'overdue' THEN amount - paid_amount ELSE 0 END) AS pending_amount,
                COUNT(DISTINCT CASE WHEN status = 'overdue' THEN student_id END) AS overdue_students,
                SUM(CASE WHEN due_date BETWEEN ? AND ? THEN amount - paid_amount ELSE 0 END) AS next7,
                SUM(CASE WHEN due_date = ? THEN amount - paid_amount ELSE 0 END) AS due_today
            ", [$today, $now->addDays(7)->toDateString(), $today])
            ->first();

        $otherIncomeMonth = (string) $b('finance_entries')->whereNull('voided_at')->where('direction', 'income')->where('entry_date', '>=', $now->startOfMonth()->toDateString())->sum('amount');
        $expenseMonth = (string) $b('finance_entries')->whereNull('voided_at')->where('direction', 'expense')->where('entry_date', '>=', $now->startOfMonth()->toDateString())->sum('amount');
        $incomeMonth = bcadd($collectedMonth, $otherIncomeMonth, 2);

        // ---- Akademik ve iletişim
        $activeTeachers = $b('teachers')->whereNull('deleted_at')->where('is_active', true)->count();

        $upcomingExams = $b('exams')->whereNull('deleted_at')->where('exam_date', '>=', $today)
            ->orderBy('exam_date')->limit(4)->get(['id', 'name', 'exam_date', 'status'])->map(fn ($r) => (array) $r)->values()->all();

        $nextLessons = DB::table('lesson_sessions as ls')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->join('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->join('teachers as t', 't.id', '=', 'ls.teacher_id')
            ->where('ls.branch_id', $branchId)->where('ls.status', '!=', 'cancelled')
            ->where('ls.ends_at', '>', $now)->where('ls.starts_at', '<=', $now->addHours(24))
            ->orderBy('ls.starts_at')->limit(6)
            ->get(['ls.id', 'ls.starts_at', 'ls.ends_at', 's.name as subject', 's.color', 'cg.name as class_group', 'c.name as classroom',
                DB::raw("CONCAT(t.first_name, ' ', t.last_name) as teacher")])
            // Önbelleğe düz dizi yaz: Collection önbellekten __PHP_Incomplete_Class olarak döner
            ->map(fn ($r) => (array) $r)->values()->all();

        $messages = $b('outbound_messages')
            ->selectRaw("SUM(created_at >= ?) AS today, SUM(created_at >= ?) AS month, SUM(status = 'failed' AND created_at >= ?) AS failed_today", [$today, $monthStart, $today])
            ->first();

        // ---- Dikkat gerektirenler (akıllı aksiyonlar)
        $riskHigh = DB::table('student_risk_scores as r')->join('students as s', 's.id', '=', 'r.student_id')
            ->where('s.branch_id', $branchId)->whereNull('s.deleted_at')->whereIn('s.status', ['active', 'enrolled'])->where('r.level', 'high')->count();
        $leadsDue = $b('leads')->whereNull('deleted_at')->whereNotIn('stage', ['won', 'lost'])
            ->whereNotNull('next_action_at')->where('next_action_at', '<=', $now->endOfDay())->count();
        $attendancePending = $b('lesson_sessions')->where('date', $today)->where('status', '!=', 'cancelled')
            ->where('ends_at', '<=', $now)->whereNull('attendance_taken_at')
            ->whereNotExists(fn ($q) => $q->from('attendances')->whereColumn('attendances.lesson_session_id', 'lesson_sessions.id'))
            ->count();

        $discipline = ['students' => 0, 'open' => 0];
        if (\App\Services\Discipline\DisciplineStanding::ready()) {
            try {
                $discipline = [
                    'students' => count(app(\App\Services\Discipline\DisciplineStanding::class)->attentionList($branchId, 500, 'warning')),
                    'open' => $b('discipline_incidents')->whereNull('deleted_at')->where('kind', 'negative')->whereIn('status', ['open', 'review'])->count(),
                ];
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'generated_at' => $now->toAtomString(),
            'attention' => [
                'risk_high' => $riskHigh,
                'leads_due' => $leadsDue,
                'attendance_pending' => $attendancePending,
                // Disiplin: dönem net puanı uyarı eşiğini aşan öğrenciler + açık/incelemedeki olaylar
                'discipline_students' => $discipline['students'],
                'discipline_open' => $discipline['open'],
            ],
            'institution' => [
                'students_total' => (int) $students->total,
                'students_active' => (int) $students->active,
                'students_frozen' => (int) $students->frozen,
                'expected_today' => $expectedToday,
                'arrived_today' => (int) $presence->arrived,
                'not_arrived_today' => $firstLessonStarted ? max(0, $expectedToday - (int) $presence->arrived) : 0,
                'inside_now' => (int) $presence->inside,
                'absent_marked_today' => $absentToday,
                'late_today' => $lateToday,
                'lessons_today' => (int) $sessions->total,
                'lessons_in_progress' => (int) $sessions->in_progress,
                'lessons_done' => (int) $sessions->done,
                'teachers_active' => $activeTeachers,
                'teachers_teaching_today' => (int) $sessions->teachers,
                'classrooms_total' => $classrooms,
                'classrooms_occupied' => $occupiedNow,
                'classrooms_free' => max(0, $classrooms - $occupiedNow),
            ],
            'finance' => [
                'collected_today' => $collectedToday,
                'collected_month' => $collectedMonth,
                'receivable' => (string) ($inst->receivable ?? 0),
                'pending_count' => (int) $inst->pending_count,
                'pending_amount' => (string) ($inst->pending_amount ?? 0),
                'overdue_count' => (int) $inst->overdue_count,
                'overdue_amount' => (string) ($inst->overdue_amount ?? 0),
                'overdue_students' => (int) $inst->overdue_students,
                'due_next_7_days' => (string) ($inst->next7 ?? 0),
                'due_today' => (string) ($inst->due_today ?? 0),
                'income_month' => $incomeMonth,
                'expense_month' => $expenseMonth,
                'net_month' => bcsub($incomeMonth, $expenseMonth, 2),
            ],
            'communication' => [
                'messages_today' => (int) $messages->today,
                'messages_month' => (int) $messages->month,
                'failed_today' => (int) $messages->failed_today,
            ],
            'upcoming_exams' => $upcomingExams,
            'next_lessons' => $nextLessons,
            'charts' => [
                'finance' => $this->financeSeries($branchId, $now),
                'enrollments' => $this->enrollmentSeries($branchId, $now),
                'attendance' => $this->attendanceSeries($branchId, $now),
                'occupancy' => $this->occupancy($branchId),
                'exam_trend' => $this->examTrend($branchId),
                'classroom_usage' => $this->classroomUsage($branchId, $now),
                'teacher_load' => $this->teacherLoad($branchId, $now),
                'collection_rate' => $this->collectionRate($branchId, $now),
            ],
        ];
    }

    private function financeSeries(int $branchId, CarbonImmutable $now): array
    {
        $from = $now->subMonths(11)->startOfMonth();

        $payments = DB::table('payments')->where('branch_id', $branchId)->whereNull('voided_at')->where('paid_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') AS m, SUM(amount) AS total")->groupBy('m')->pluck('total', 'm');

        $entries = DB::table('finance_entries')->where('branch_id', $branchId)->whereNull('voided_at')->where('entry_date', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(entry_date, '%Y-%m') AS m, direction, SUM(amount) AS total")->groupBy('m', 'direction')->get();

        $series = [];
        for ($i = 0; $i < 12; $i++) {
            $key = $from->addMonths($i)->format('Y-m');
            $collections = (float) ($payments[$key] ?? 0);
            $otherIncome = (float) $entries->where('m', $key)->where('direction', 'income')->sum('total');
            $expense = (float) $entries->where('m', $key)->where('direction', 'expense')->sum('total');
            $series[] = ['month' => $key, 'collections' => $collections, 'income' => $collections + $otherIncome, 'expense' => $expense, 'net' => $collections + $otherIncome - $expense];
        }

        return $series;
    }

    private function enrollmentSeries(int $branchId, CarbonImmutable $now): array
    {
        $from = $now->subMonths(11)->startOfMonth();
        $rows = DB::table('enrollments')->where('branch_id', $branchId)->whereNull('deleted_at')->where('enrolled_on', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(enrolled_on, '%Y-%m') AS m, COUNT(*) AS total")->groupBy('m')->pluck('total', 'm');

        return collect(range(0, 11))->map(fn ($i) => ['month' => $k = $from->addMonths($i)->format('Y-m'), 'count' => (int) ($rows[$k] ?? 0)])->all();
    }

    private function attendanceSeries(int $branchId, CarbonImmutable $now): array
    {
        $from = $now->subDays(13)->toDateString();
        $rows = DB::table('attendances')->where('branch_id', $branchId)->where('date', '>=', $from)
            ->selectRaw('date, status, COUNT(*) AS total')->groupBy('date', 'status')->get();

        return collect(range(13, 0))->map(function ($d) use ($now, $rows) {
            $date = $now->subDays($d)->toDateString();
            $day = $rows->where('date', $date);

            return [
                'date' => $date,
                'present' => (int) $day->where('status', 'present')->sum('total'),
                'late' => (int) $day->where('status', 'late')->sum('total'),
                'absent' => (int) $day->where('status', 'absent')->sum('total'),
                'excused' => (int) $day->whereIn('status', ['excused', 'medical'])->sum('total'),
            ];
        })->filter(fn ($r) => $r['present'] + $r['late'] + $r['absent'] + $r['excused'] > 0)->values()->all();
    }

    private function occupancy(int $branchId): array
    {
        return DB::table('class_groups as cg')
            ->leftJoin('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'cg.id')->whereNull('cgs.left_on'))
            ->where('cg.branch_id', $branchId)->whereNull('cg.deleted_at')->where('cg.is_active', true)
            ->groupBy('cg.id', 'cg.name', 'cg.capacity')
            ->orderBy('cg.name')
            ->get(['cg.id', 'cg.name', 'cg.capacity', DB::raw('COUNT(cgs.id) AS students')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'capacity' => (int) $r->capacity, 'students' => (int) $r->students])
            ->all();
    }

    private function examTrend(int $branchId): array
    {
        return DB::table('exams as e')
            ->join('exam_types as et', 'et.id', '=', 'e.exam_type_id')
            ->join('exam_results as r', 'r.exam_id', '=', 'e.id')
            ->where('e.branch_id', $branchId)->whereNull('e.deleted_at')->where('e.status', 'results_published')
            ->groupBy('e.id', 'e.name', 'e.exam_date', 'et.code')
            ->orderByDesc('e.exam_date')->limit(12)
            ->get(['e.id', 'e.name', 'e.exam_date', 'et.code as type', DB::raw('ROUND(AVG(r.net), 2) AS avg_net'), DB::raw('COUNT(r.id) AS participants')])
            ->reverse()->map(fn ($r) => (array) $r)->values()->all();
    }

    /** Bu hafta derslik başına planlı ders dakikası / haftalık kullanılabilir süre (6 gün × 9 saat). */
    private function classroomUsage(int $branchId, CarbonImmutable $now): array
    {
        $weekStart = $now->startOfWeek()->toDateString();
        $weekEnd = $now->endOfWeek()->toDateString();
        $available = 6 * 9 * 60;

        return DB::table('classrooms as c')
            ->leftJoin('lesson_sessions as ls', fn ($j) => $j->on('ls.classroom_id', '=', 'c.id')->whereBetween('ls.date', [$weekStart, $weekEnd])->where('ls.status', '!=', 'cancelled'))
            ->where('c.branch_id', $branchId)->whereNull('c.deleted_at')->where('c.is_active', true)
            ->groupBy('c.id', 'c.name', 'c.kind')
            ->orderBy('c.name')
            ->get(['c.id', 'c.name', 'c.kind', DB::raw('COALESCE(SUM(TIMESTAMPDIFF(MINUTE, ls.starts_at, ls.ends_at)), 0) AS minutes')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'minutes' => (int) $r->minutes, 'rate' => round(min(100, $r->minutes / $available * 100), 1)])
            ->all();
    }

    private function teacherLoad(int $branchId, CarbonImmutable $now): array
    {
        $weekStart = $now->startOfWeek()->toDateString();
        $weekEnd = $now->endOfWeek()->toDateString();

        return DB::table('teachers as t')
            ->join('lesson_sessions as ls', 'ls.teacher_id', '=', 't.id')
            ->where('t.branch_id', $branchId)->whereNull('t.deleted_at')
            ->whereBetween('ls.date', [$weekStart, $weekEnd])->where('ls.status', '!=', 'cancelled')
            ->groupBy('t.id', 't.first_name', 't.last_name', 't.max_weekly_hours')
            ->orderByDesc(DB::raw('SUM(TIMESTAMPDIFF(MINUTE, ls.starts_at, ls.ends_at))'))
            ->limit(12)
            ->get(['t.id', DB::raw("CONCAT(t.first_name, ' ', t.last_name) AS name"), 't.max_weekly_hours',
                DB::raw('ROUND(SUM(TIMESTAMPDIFF(MINUTE, ls.starts_at, ls.ends_at)) / 60, 1) AS hours'), DB::raw('COUNT(ls.id) AS lessons')])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function collectionRate(int $branchId, CarbonImmutable $now): array
    {
        return collect(range(5, 0))->map(function ($i) use ($branchId, $now) {
            $month = $now->subMonths($i);
            $row = DB::table('installments')->where('branch_id', $branchId)->where('status', '!=', 'cancelled')
                ->whereBetween('due_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
                ->selectRaw('SUM(amount) AS due, SUM(paid_amount) AS paid')->first();
            $due = (float) ($row->due ?? 0);

            return ['month' => $month->format('Y-m'), 'due' => $due, 'paid' => (float) ($row->paid ?? 0), 'rate' => $due > 0 ? round($row->paid / $due * 100, 1) : null];
        })->all();
    }
}
