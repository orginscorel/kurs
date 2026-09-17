<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\LessonSession;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Yedek öğretmen botu: bir oturum için uygun öğretmenleri puanlar ve atar.
 * Sert eleme: aynı saatte dersi/etüdü var, izinli, uygunluk penceresi dışında, haftalık üst sınır dolu.
 */
class SubstituteFinder
{
    public function __construct(private readonly ScheduleService $schedules) {}

    public function candidates(LessonSession $s, int $limit = 12): array
    {
        $s->loadMissing(['subject:id,name', 'teacher:id,first_name,last_name', 'classGroup:id,name', 'classroom:id,name']);
        $start = CarbonImmutable::parse($s->starts_at);
        $end = CarbonImmutable::parse($s->ends_at);
        $date = $start->toDateString();
        $weekStart = TimeSlots::weekStart($start);
        $weekday = $start->dayOfWeekIso;

        $teachers = Teacher::query()->where('is_active', true)->whereKeyNot($s->teacher_id)->get(['id', 'first_name', 'last_name', 'max_weekly_hours', 'color']);
        $ids = $teachers->pluck('id');
        $competent = DB::table('teacher_subject')->where('subject_id', $s->subject_id)->whereIn('teacher_id', $ids)->pluck('teacher_id')->flip();

        $busy = DB::table('lesson_sessions')->whereIn('teacher_id', $ids)->where('status', '!=', 'cancelled')->where('id', '!=', $s->id)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->pluck('teacher_id')
            ->merge(DB::table('study_sessions')->whereIn('teacher_id', $ids)->whereIn('status', ['requested', 'approved'])
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->pluck('teacher_id'))->flip();
        $onLeave = DB::table('teacher_leaves')->whereIn('teacher_id', $ids)->where('status', 'approved')
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->pluck('teacher_id')->flip();
        $availability = DB::table('teacher_availabilities')->whereIn('teacher_id', $ids)->get()->groupBy('teacher_id');
        $weekLoads = DB::table('lesson_sessions')->whereIn('teacher_id', $ids)->where('status', '!=', 'cancelled')
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])->groupBy('teacher_id')->pluck(DB::raw('COUNT(*)'), 'teacher_id');
        $dayRows = DB::table('lesson_sessions')->whereIn('teacher_id', $ids)->where('status', '!=', 'cancelled')->where('date', $date)->get(['teacher_id', 'starts_at', 'ends_at'])->groupBy('teacher_id');
        $knows = DB::table('lesson_schedules')->whereNull('deleted_at')->where('class_group_id', $s->class_group_id)->whereIn('teacher_id', $ids)->pluck('teacher_id')->flip();

        $out = [];
        $excluded = ['busy' => 0, 'leave' => 0, 'unavailable' => 0, 'max' => 0];
        $startMin = $start->hour * 60 + $start->minute;
        $endMin = $end->hour * 60 + $end->minute;

        foreach ($teachers as $t) {
            if (isset($onLeave[$t->id])) {
                $excluded['leave']++;

                continue;
            }
            if (isset($busy[$t->id])) {
                $excluded['busy']++;

                continue;
            }
            if (isset($availability[$t->id])) {
                $fits = $availability[$t->id]->contains(fn ($a) => (int) $a->weekday === $weekday && TimeSlots::toMinutes($a->starts_at) <= $startMin && TimeSlots::toMinutes($a->ends_at) >= $endMin);
                if (! $fits) {
                    $excluded['unavailable']++;

                    continue;
                }
            }
            $max = (int) ($t->max_weekly_hours ?: 40);
            $week = (int) ($weekLoads[$t->id] ?? 0);
            if ($week >= $max) {
                $excluded['max']++;

                continue;
            }
            $day = $dayRows[$t->id] ?? collect();
            $adjacent = $day->contains(fn ($r) => abs(CarbonImmutable::parse($r->ends_at)->diffInMinutes($start, false)) <= 15 || abs($end->diffInMinutes(CarbonImmutable::parse($r->starts_at), false)) <= 15);
            $isCompetent = isset($competent[$t->id]);

            $scored = SubstituteScorer::score(['competent' => $isCompetent, 'day_load' => $day->count(), 'week_load' => $week, 'max_week' => $max, 'adjacent' => $adjacent, 'knows_class' => isset($knows[$t->id])]);
            $out[] = ['id' => $t->id, 'name' => $t->full_name, 'competent' => $isCompetent, 'score' => $scored['score'], 'reasons' => $scored['reasons'],
                'day_load' => $day->count(), 'week_load' => $week, 'max_week' => $max];
        }

        $sorted = SubstituteScorer::sort($out);
        $competentList = array_values(array_filter($sorted, fn ($c) => $c['competent']));
        $others = array_slice(array_values(array_filter($sorted, fn ($c) => ! $c['competent'])), 0, 4);

        return [
            'session' => [
                'id' => $s->id, 'date' => $date, 'starts_at' => $start->format('H:i'), 'ends_at' => $end->format('H:i'),
                'subject' => $s->subject?->name, 'class_group' => $s->classGroup?->name, 'classroom' => $s->classroom?->name, 'teacher' => $s->teacher?->full_name,
                'teacher_on_leave' => DB::table('teacher_leaves')->where('teacher_id', $s->teacher_id)->where('status', 'approved')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->exists(),
            ],
            'candidates' => array_slice($competentList, 0, $limit),
            'others' => $others,
            'excluded' => $excluded,
        ];
    }

    public function assign(LessonSession $s, int $teacherId): LessonSession
    {
        if ($s->attendance_taken_at) {
            throw new BusinessRuleException('Yoklaması alınmış derse yedek öğretmen atanamaz.', 'attendance_taken');
        }
        if ($teacherId === $s->teacher_id) {
            throw new BusinessRuleException('Seçilen öğretmen zaten bu dersin öğretmeni.', 'same_teacher');
        }
        $teacher = Teacher::query()->where('is_active', true)->findOrFail($teacherId);
        $start = CarbonImmutable::parse($s->starts_at);
        $end = CarbonImmutable::parse($s->ends_at);
        $windows = DB::table('teacher_availabilities')->where('teacher_id', $teacher->id)->get();
        if ($windows->isNotEmpty() && ! $windows->contains(fn ($a) => (int) $a->weekday === $start->dayOfWeekIso && TimeSlots::toMinutes($a->starts_at) <= $start->hour * 60 + $start->minute && TimeSlots::toMinutes($a->ends_at) >= $end->hour * 60 + $end->minute)) {
            throw new BusinessRuleException("{$teacher->full_name} bu saatte uygunluk takvimi dışında.", 'teacher_unavailable');
        }

        return $this->schedules->reassignSession($s, null, $teacher->id); // çakışma + izin denetimi, denetim kaydı, bildirim
    }

    /**
     * İzin etkisi: bir öğretmenin tarih aralığındaki (iptal edilmemiş, gelecekteki) oturumları.
     * Öğretmen verilmezse aralıkta onaylı izni olan tüm öğretmenler.
     */
    public function leaveImpact(CarbonImmutable $from, CarbonImmutable $to, ?int $teacherId): array
    {
        $leaves = DB::table('teacher_leaves as l')->join('teachers as t', 't.id', '=', 'l.teacher_id')
            ->where('t.branch_id', app(\App\Support\BranchContext::class)->require())
            ->where('l.status', 'approved')->where('l.starts_on', '<=', $to->toDateString())->where('l.ends_on', '>=', $from->toDateString())
            ->when($teacherId, fn ($q) => $q->where('l.teacher_id', $teacherId))
            ->orderBy('l.starts_on')->get(['l.id', 'l.teacher_id', 'l.starts_on', 'l.ends_on', 'l.kind', 'l.reason', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        $teacherIds = $teacherId ? [$teacherId] : $leaves->pluck('teacher_id')->unique()->all();
        $sessions = LessonSession::query()->with(['subject:id,name', 'classGroup:id,name', 'classroom:id,name', 'teacher:id,first_name,last_name'])
            ->whereIn('teacher_id', $teacherIds)->where('status', '!=', 'cancelled')->where('starts_at', '>', now())
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('starts_at')->get();

        return [
            'leaves' => $leaves->map(fn ($l) => ['id' => $l->id, 'teacher_id' => $l->teacher_id, 'teacher' => $l->teacher, 'starts_on' => $l->starts_on, 'ends_on' => $l->ends_on, 'kind' => $l->kind, 'reason' => $l->reason])->values(),
            'sessions' => $sessions->map(fn (LessonSession $s) => [
                'id' => $s->id, 'date' => $s->date->toDateString(), 'starts_at' => $s->starts_at->format('H:i'), 'ends_at' => $s->ends_at->format('H:i'),
                'subject' => $s->subject?->name, 'class_group' => $s->classGroup?->name, 'classroom' => $s->classroom?->name,
                'teacher_id' => $s->teacher_id, 'teacher' => $s->teacher?->full_name, 'attendance_taken' => (bool) $s->attendance_taken_at,
                'on_leave' => $leaves->contains(fn ($l) => (int) $l->teacher_id === $s->teacher_id && $s->date->toDateString() >= $l->starts_on && $s->date->toDateString() <= $l->ends_on),
            ])->values(),
        ];
    }
}
