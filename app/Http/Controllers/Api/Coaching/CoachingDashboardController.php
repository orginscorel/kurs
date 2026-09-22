<?php

namespace App\Http\Controllers\Api\Coaching;

use App\Http\Controllers\Api\ApiController;
use App\Models\CoachingAssignment;
use App\Models\CoachingPlan;
use App\Models\CoachingSession;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Koç panosu: koçun kendi öğrencileri, yaklaşan/geciken görüşmeler, bu haftanın planları. */
class CoachingDashboardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        // Varsayılan olarak giriş yapan kişi; müdür/rehber başka koçu seçebilir.
        $coachId = $request->integer('coach_id') ?: (int) $request->user()->id;
        $today = CarbonImmutable::today();
        $weekStart = $today->startOfWeek()->toDateString();

        $assignments = CoachingAssignment::query()
            ->where('coach_id', $coachId)->where('is_active', true)
            ->with('student:id,full_name,student_no,school_grade,field')
            ->get()
            ->filter(fn (CoachingAssignment $a) => $a->student !== null);

        $studentIds = $assignments->pluck('student_id')->all();

        $lastSessions = CoachingSession::query()->whereIn('student_id', $studentIds)
            ->selectRaw('student_id, MAX(held_at) as last_at')->groupBy('student_id')->pluck('last_at', 'student_id');

        $nextSessions = CoachingSession::query()->whereIn('student_id', $studentIds)
            ->whereNotNull('next_session_on')
            ->selectRaw('student_id, MIN(next_session_on) as next_on')->groupBy('student_id')->pluck('next_on', 'student_id');

        $plans = CoachingPlan::query()->whereIn('student_id', $studentIds)->whereDate('week_start', $weekStart)
            ->with('items:id,coaching_plan_id,is_done')->get()->keyBy('student_id');

        $students = $assignments->map(function (CoachingAssignment $a) use ($lastSessions, $nextSessions, $plans, $today) {
            $s = $a->student;
            $plan = $plans->get($a->student_id);
            $items = $plan?->items ?? collect();
            $nextOn = $nextSessions->get($a->student_id);

            return [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'student_no' => $s->student_no,
                'school_grade' => $s->school_grade,
                'field' => $s->field,
                'last_session_at' => $lastSessions->get($a->student_id),
                'next_session_on' => $nextOn,
                'overdue' => $nextOn ? CarbonImmutable::parse($nextOn)->lt($today) : false,
                'has_plan' => $plan !== null,
                'plan_progress' => $items->count() ? (int) round($items->where('is_done', true)->count() / $items->count() * 100) : null,
            ];
        })->values();

        $upcoming = CoachingSession::query()->whereIn('student_id', $studentIds)
            ->whereNotNull('next_session_on')->where('next_session_on', '>=', $today)
            ->with('student:id,full_name,student_no')
            ->orderBy('next_session_on')->limit(15)->get()
            ->map(fn (CoachingSession $s) => [
                'id' => $s->id,
                'student' => $s->student?->only(['id', 'full_name', 'student_no']),
                'next_session_on' => $s->next_session_on?->toDateString(),
            ])->values();

        return response()->json([
            'coach_id' => $coachId,
            'week_start' => $weekStart,
            'stats' => [
                'students' => $assignments->count(),
                'overdue' => $students->where('overdue', true)->count(),
                'plans_this_week' => $plans->count(),
                'without_plan' => $students->where('has_plan', false)->count(),
            ],
            'students' => $students,
            'upcoming' => $upcoming,
        ]);
    }

    /** Öğrenci koçluk özeti: aktif koç + görüşme geçmişi sayısı + bu hafta planı. */
    public function student(Request $request, Student $student): JsonResponse
    {
        $active = CoachingAssignment::query()->where('student_id', $student->id)->where('is_active', true)
            ->with('coach:id,name')->first();

        $weekStart = CarbonImmutable::today()->startOfWeek()->toDateString();
        $currentPlan = CoachingPlan::query()->where('student_id', $student->id)->whereDate('week_start', $weekStart)->exists();

        return response()->json([
            'student' => $student->only(['id', 'full_name', 'student_no', 'school_grade', 'field']),
            'coach' => $active?->coach ? ['id' => $active->coach->id, 'name' => $active->coach->name, 'assigned_at' => $active->assigned_at] : null,
            'session_count' => CoachingSession::query()->where('student_id', $student->id)->count(),
            'has_current_plan' => $currentPlan,
            'current_week_start' => $weekStart,
        ]);
    }
}
