<?php

namespace App\Http\Controllers\Api;

use App\Support\BranchContext;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Bugün" — yöneticinin her sabah baktığı operasyon ekranı + canlı kurum haritası.
 */
class OperationsController extends ApiController
{
    public function today(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $now = CarbonImmutable::now();
        $date = $request->date('date') ? CarbonImmutable::parse($request->date('date')) : $now;
        $day = $date->toDateString();
        $showPhones = $request->user()->can('students.view_sensitive');

        $sessions = DB::table('lesson_sessions as ls')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->join('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->join('teachers as t', 't.id', '=', 'ls.teacher_id')
            ->leftJoin(DB::raw('(SELECT class_group_id, COUNT(*) AS cnt FROM class_group_student WHERE left_on IS NULL GROUP BY class_group_id) AS roster'), 'roster.class_group_id', '=', 'ls.class_group_id')
            ->leftJoin(DB::raw("(SELECT lesson_session_id, SUM(status = 'present') AS present, SUM(status = 'late') AS late, SUM(status = 'absent') AS absent, SUM(status IN ('excused','medical')) AS excused FROM attendances WHERE date = '{$day}' GROUP BY lesson_session_id) AS att"), 'att.lesson_session_id', '=', 'ls.id')
            ->where('ls.branch_id', $branchId)->where('ls.date', $day)
            ->orderBy('ls.starts_at')->orderBy('c.name')
            ->get([
                'ls.id', 'ls.starts_at', 'ls.ends_at', 'ls.status', 'ls.attendance_taken_at', 'ls.class_group_id', 'ls.classroom_id',
                's.name as subject', 's.color as subject_color', 'cg.name as class_group', 'c.name as classroom', 'c.capacity',
                't.id as teacher_id', DB::raw("CONCAT(t.first_name, ' ', t.last_name) as teacher"),
                DB::raw('COALESCE(roster.cnt, 0) AS roster'), DB::raw('COALESCE(att.present, 0) AS present'), DB::raw('COALESCE(att.late, 0) AS late'),
                DB::raw('COALESCE(att.absent, 0) AS absent'), DB::raw('COALESCE(att.excused, 0) AS excused'),
            ])
            ->map(function ($s) use ($now) {
                $s->phase = $s->status === 'cancelled' ? 'cancelled'
                    : ($now->lt($s->starts_at) ? 'upcoming' : ($now->lt($s->ends_at) ? 'in_progress' : 'done'));

                return $s;
            });

        $expectedIds = DB::table('lesson_sessions as ls')
            ->join('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'ls.class_group_id')->whereNull('cgs.left_on'))
            ->where('ls.branch_id', $branchId)->where('ls.date', $day)->where('ls.status', '!=', 'cancelled')
            ->distinct()->pluck('cgs.student_id');

        $firstStart = DB::table('lesson_sessions as ls')
            ->join('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'ls.class_group_id')->whereNull('cgs.left_on'))
            ->where('ls.branch_id', $branchId)->where('ls.date', $day)->where('ls.status', '!=', 'cancelled')
            ->groupBy('cgs.student_id')->selectRaw('cgs.student_id, MIN(ls.starts_at) AS first_start')
            ->pluck('first_start', 'student_id');

        $presences = DB::table('daily_presences')->where('branch_id', $branchId)->where('date', $day)
            ->get(['student_id', 'first_entry_at', 'last_exit_at', 'is_inside', 'minutes_inside'])->keyBy('student_id');

        $notArrivedIds = $expectedIds->filter(fn ($id) => ! isset($presences[$id]) || ! $presences[$id]->first_entry_at)
            ->filter(fn ($id) => isset($firstStart[$id]) && $now->gte($firstStart[$id]));

        $notArrived = DB::table('students as st')
            ->leftJoin('class_group_student as cgs', fn ($j) => $j->on('cgs.student_id', '=', 'st.id')->whereNull('cgs.left_on'))
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
            ->leftJoin('guardian_student as gs', fn ($j) => $j->on('gs.student_id', '=', 'st.id')->where('gs.is_primary', true))
            ->leftJoin('guardians as g', 'g.id', '=', 'gs.guardian_id')
            ->whereIn('st.id', $notArrivedIds->take(300))
            ->orderBy('st.full_name')
            ->get(['st.id', 'st.full_name', 'st.student_no', 'st.photo_path', 'cg.name as class_group',
                DB::raw("CONCAT(g.first_name, ' ', g.last_name) AS guardian"), 'g.phone as guardian_phone'])
            ->unique('id')->values()
            ->map(function ($r) use ($firstStart, $showPhones) {
                $r->first_lesson_at = $firstStart[$r->id] ?? null;
                $r->guardian_phone = $showPhones ? $r->guardian_phone : Sensitive::maskPhone($r->guardian_phone);

                return $r;
            });

        $late = DB::table('attendances as a')
            ->join('students as st', 'st.id', '=', 'a.student_id')
            ->join('lesson_sessions as ls', 'ls.id', '=', 'a.lesson_session_id')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->where('a.branch_id', $branchId)->where('a.date', $day)->where('a.status', 'late')
            ->orderByDesc('a.late_minutes')->limit(100)
            ->get(['a.id', 'st.id as student_id', 'st.full_name', 'a.late_minutes', 's.name as subject', 'ls.starts_at']);

        // Sınıflar şu an: her sınıfın kendi programı var — sınıf başına şu anki/sıradaki ders ve kurumdaki öğrenci sayısı
        $insideIds = $presences->filter(fn ($p) => $p->is_inside)->keys();
        $arrivedIds = $presences->filter(fn ($p) => $p->first_entry_at)->keys();

        $groups = DB::table('class_groups as cg')
            ->leftJoin('programs as p', 'p.id', '=', 'cg.program_id')
            ->where('cg.branch_id', $branchId)->whereNull('cg.deleted_at')->where('cg.is_active', true)
            ->orderBy('cg.name')
            ->get(['cg.id', 'cg.name', 'p.name as program']);

        $rosters = DB::table('class_group_student')->whereNull('left_on')
            ->whereIn('class_group_id', $groups->pluck('id'))
            ->get(['class_group_id', 'student_id'])->groupBy('class_group_id');

        $classes = $groups->map(function ($g) use ($sessions, $rosters, $insideIds, $arrivedIds) {
            $groupSessions = $sessions->where('class_group_id', $g->id)->where('phase', '!=', 'cancelled')->values();
            $current = $groupSessions->firstWhere('phase', 'in_progress');
            $next = $groupSessions->firstWhere('phase', 'upcoming');
            $active = $current ?? $next;
            $roster = ($rosters[$g->id] ?? collect())->pluck('student_id');

            return [
                'id' => $g->id,
                'name' => $g->name,
                'program' => $g->program,
                'roster' => $roster->count(),
                'arrived' => $roster->intersect($arrivedIds)->count(),
                'inside' => $roster->intersect($insideIds)->count(),
                'state' => $current ? 'in_lesson' : ($next ? 'next' : ($groupSessions->isEmpty() ? 'no_lessons' : 'done')),
                'lessons_today' => $groupSessions->count(),
                'lessons_done' => $groupSessions->where('phase', 'done')->count(),
                'session' => $active ? [
                    'id' => $active->id, 'subject' => $active->subject, 'teacher' => $active->teacher, 'classroom' => $active->classroom,
                    'starts_at' => $active->starts_at, 'ends_at' => $active->ends_at,
                    'present' => (int) $active->present, 'late' => (int) $active->late, 'absent' => (int) $active->absent,
                ] : null,
            ];
        })->sortBy(fn ($c) => ['in_lesson' => 0, 'next' => 1, 'done' => 2, 'no_lessons' => 3][$c['state']])->values();

        $finance = null;
        if ($request->user()->can('finance.view')) {
            $finance = [
                'expected' => (string) DB::table('installments')->where('branch_id', $branchId)->where('due_date', $day)->where('status', '!=', 'cancelled')->sum(DB::raw('amount')),
                'expected_open' => (string) DB::table('installments')->where('branch_id', $branchId)->where('due_date', $day)->whereIn('status', ['pending', 'partial', 'overdue'])->sum(DB::raw('amount - paid_amount')),
                'collected' => (string) DB::table('payments')->where('branch_id', $branchId)->whereNull('voided_at')->whereDate('paid_at', $day)->sum('amount'),
                'payments_count' => DB::table('payments')->where('branch_id', $branchId)->whereNull('voided_at')->whereDate('paid_at', $day)->count(),
                'due_today' => DB::table('installments as i')
                    ->join('students as st', 'st.id', '=', 'i.student_id')
                    ->where('i.branch_id', $branchId)->where('i.due_date', $day)->whereIn('i.status', ['pending', 'partial', 'overdue'])
                    ->orderByDesc(DB::raw('i.amount - i.paid_amount'))->limit(30)
                    ->get(['i.id', 'st.id as student_id', 'st.full_name', 'i.amount', 'i.paid_amount', 'i.sequence']),
            ];
        }

        $lateCount = DB::table('attendances')->where('branch_id', $branchId)->where('date', $day)->where('status', 'late')->distinct()->count('student_id');

        return response()->json([
            'date' => $day,
            'now' => $now->toAtomString(),
            'summary' => [
                'expected' => $expectedIds->count(),
                'arrived' => $presences->filter(fn ($p) => $p->first_entry_at)->count(),
                'inside' => $insideIds->count(),
                'late' => $lateCount,
                'not_arrived' => $notArrivedIds->count(),
                'lessons' => $sessions->where('phase', '!=', 'cancelled')->count(),
                'lessons_cancelled' => $sessions->where('phase', 'cancelled')->count(),
                'lessons_in_progress' => $sessions->where('phase', 'in_progress')->count(),
                'attendance_pending' => $sessions->where('phase', 'done')->filter(fn ($s) => ! $s->attendance_taken_at && $s->present + $s->late + $s->absent + $s->excused === 0)->count(),
                'teachers' => $sessions->where('phase', '!=', 'cancelled')->pluck('teacher_id')->unique()->count(),
                'classrooms' => $sessions->where('phase', '!=', 'cancelled')->pluck('classroom_id')->unique()->count(),
            ],
            'finance' => $finance,
            'sessions' => $sessions->values(),
            'not_arrived' => $notArrived,
            'late' => $late,
            'classes' => $classes,
        ]);
    }
}
