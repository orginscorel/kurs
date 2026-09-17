<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\Classroom;
use App\Models\LessonSession;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Telafi / tek oturum taşıma. Eski oturum "telafiye taşındı" gerekçesiyle iptal edilir, yeni tarih için
 * şablondan bağımsız bir oturum (makeup_of_id) açılır — şablon üretimi eski tarihi yeniden doldurmaz.
 */
class SessionMover
{
    public function __construct(private readonly ScheduleConflicts $conflicts) {}

    /**
     * Sınıf + öğretmen + derslik aynı anda boş dilimler. Aday saatler sınıfın zaman şablonundaki
     * dilimlerden (yoksa 09:00–21:00 arası 30 dk adımlarla) gelir.
     *
     * @return list<array{date:string, weekday:int, starts_at:string, ends_at:string, classroom_id:int, classroom:string, same_room:bool, class_load:int, note:?string}>
     */
    public function suggestions(LessonSession $s, CarbonImmutable $from, CarbonImmutable $to, int $limit = 16): array
    {
        $duration = (int) CarbonImmutable::parse($s->starts_at)->diffInMinutes(CarbonImmutable::parse($s->ends_at));
        $to = $to->min($from->addDays(30));
        $now = CarbonImmutable::now();

        $templateSlots = [];
        foreach ($s->classGroup?->timeTemplates()->where('is_active', true)->get() ?? [] as $tpl) {
            foreach ($tpl->periods() as $p) {
                $templateSlots[$p['weekday']][] = TimeSlots::toMinutes($p['start']);
            }
        }

        $size = max(1, (int) DB::table('class_group_student')->where('class_group_id', $s->class_group_id)->whereNull('left_on')->count());
        $rooms = Classroom::query()->where('is_active', true)->where('kind', '!=', 'study')->where('capacity', '>=', $size)->orderByRaw('id = ? desc', [$s->classroom_id])->orderBy('capacity')->get(['id', 'name']);
        $roomIds = $rooms->pluck('id')->all();

        $range = [$from->startOfDay(), $to->endOfDay()];
        $sessions = DB::table('lesson_sessions')->where('status', '!=', 'cancelled')->where('id', '!=', $s->id)->whereBetween('starts_at', $range)
            ->where(fn ($q) => $q->where('class_group_id', $s->class_group_id)->orWhere('teacher_id', $s->teacher_id)->orWhereIn('classroom_id', $roomIds))
            ->get(['class_group_id', 'teacher_id', 'classroom_id', 'starts_at', 'ends_at']);
        $studies = DB::table('study_sessions')->whereIn('status', ['requested', 'approved'])->whereBetween('starts_at', $range)
            ->where(fn ($q) => $q->where('teacher_id', $s->teacher_id)->orWhereIn('classroom_id', $roomIds))->get(['teacher_id', 'classroom_id', 'starts_at', 'ends_at']);
        $leaves = DB::table('teacher_leaves')->where('teacher_id', $s->teacher_id)->where('status', 'approved')
            ->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())->get(['starts_on', 'ends_on']);
        $availability = DB::table('teacher_availabilities')->where('teacher_id', $s->teacher_id)->get();
        $holidays = new HolidayCalendar(DB::table('holidays')->where('branch_id', $s->branch_id)->where('cancel_sessions', true)
            ->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())->get(['id', 'name', 'starts_on', 'ends_on'])->map(fn ($h) => (array) $h)->all());

        $busyByDay = [];
        foreach ($sessions as $r) {
            $busyByDay[substr($r->starts_at, 0, 10)][] = ['c' => (int) $r->class_group_id, 't' => (int) $r->teacher_id, 'r' => (int) $r->classroom_id, 's' => TimeSlots::toMinutes(substr($r->starts_at, 11, 5)), 'e' => TimeSlots::toMinutes(substr($r->ends_at, 11, 5))];
        }
        foreach ($studies as $r) {
            $busyByDay[substr($r->starts_at, 0, 10)][] = ['c' => 0, 't' => (int) $r->teacher_id, 'r' => (int) $r->classroom_id, 's' => TimeSlots::toMinutes(substr($r->starts_at, 11, 5)), 'e' => TimeSlots::toMinutes(substr($r->ends_at, 11, 5))];
        }

        $out = [];
        foreach (CarbonPeriod::create($from, $to) as $d) {
            $day = CarbonImmutable::instance($d);
            $date = $day->toDateString();
            $wd = $day->dayOfWeekIso;
            if ($holidays->find($date) || $leaves->contains(fn ($l) => $date >= $l->starts_on && $date <= $l->ends_on)) {
                continue;
            }
            $starts = $templateSlots[$wd] ?? ($templateSlots ? [] : range(9 * 60, 21 * 60 - $duration, 30));
            $busy = $busyByDay[$date] ?? [];
            $classLoad = count(array_filter($busy, fn ($b) => $b['c'] === (int) $s->class_group_id));

            foreach (array_unique($starts) as $st) {
                $en = $st + $duration;
                if ($day->setTime(intdiv($st, 60), $st % 60)->lte($now)) {
                    continue;
                }
                if ($availability->isNotEmpty() && ! $availability->contains(fn ($a) => (int) $a->weekday === $wd && TimeSlots::toMinutes($a->starts_at) <= $st && TimeSlots::toMinutes($a->ends_at) >= $en)) {
                    continue;
                }
                $overlapping = array_filter($busy, fn ($b) => $b['s'] < $en && $st < $b['e']);
                if (array_filter($overlapping, fn ($b) => $b['c'] === (int) $s->class_group_id || $b['t'] === (int) $s->teacher_id)) {
                    continue;
                }
                $usedRooms = array_flip(array_column($overlapping, 'r'));
                $room = $rooms->first(fn ($r) => ! isset($usedRooms[$r->id]));
                if (! $room) {
                    continue;
                }
                $out[] = [
                    'date' => $date, 'weekday' => $wd, 'starts_at' => TimeSlots::toTime($st), 'ends_at' => TimeSlots::toTime($en),
                    'classroom_id' => $room->id, 'classroom' => $room->name, 'same_room' => $room->id === $s->classroom_id, 'class_load' => $classLoad,
                    'note' => $classLoad >= 5 ? "Sınıfın o gün $classLoad dersi var" : null,
                ];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    public function move(LessonSession $s, array $data): LessonSession
    {
        if ($s->status === 'cancelled') {
            throw new BusinessRuleException('İptal edilmiş ders taşınamaz.', 'session_cancelled');
        }
        if ($s->attendance_taken_at || $s->attendances()->exists()) {
            throw new BusinessRuleException('Yoklaması alınmış ders taşınamaz.', 'attendance_taken');
        }
        $start = CarbonImmutable::parse($data['date'].' '.$data['starts_at']);
        $end = CarbonImmutable::parse($data['date'].' '.$data['ends_at']);
        if ($start->lte(CarbonImmutable::now())) {
            throw new BusinessRuleException('Telafi dersi geçmiş bir zamana taşınamaz.', 'in_past');
        }
        $teacherId = (int) ($data['teacher_id'] ?? $s->teacher_id);
        $roomId = (int) ($data['classroom_id'] ?? $s->classroom_id);

        $holiday = (new HolidayCalendar(DB::table('holidays')->where('branch_id', $s->branch_id)->where('cancel_sessions', true)->get(['id', 'name', 'starts_on', 'ends_on'])->map(fn ($h) => (array) $h)->all()))->find($start->toDateString());
        if ($holiday) {
            throw new BusinessRuleException("Seçilen tarih tatil: {$holiday['name']}.", 'holiday');
        }
        ScheduleConflicts::throwIfAny($this->conflicts->forRange($start, $end, $teacherId, $roomId, $s->class_group_id, $s->id));

        return DB::transaction(function () use ($s, $start, $end, $teacherId, $roomId, $data) {
            $note = trim((string) ($data['reason'] ?? ''));
            $s->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr('Telafiye taşındı: '.$start->format('d.m.Y H:i').($note ? " — $note" : ''), 0, 300)])->save();

            $makeup = LessonSession::query()->create([
                'branch_id' => $s->branch_id, 'lesson_schedule_id' => null, 'makeup_of_id' => $s->id, 'class_group_id' => $s->class_group_id,
                'subject_id' => $s->subject_id, 'teacher_id' => $teacherId, 'classroom_id' => $roomId,
                'date' => $start->toDateString(), 'starts_at' => $start, 'ends_at' => $end, 'status' => 'scheduled',
                'topic_note' => $note ? mb_substr("Telafi: $note", 0, 500) : 'Telafi dersi',
            ]);
            $makeup->load(['classroom:id,name', 'classGroup:id,name', 'subject:id,name']);

            Audit::log('session.rescheduled', sprintf('%s sınıfının %s tarihli %s dersini %s %s saatine (%s) taşıdı.',
                $makeup->classGroup->name, $s->date->format('d.m.Y'), $makeup->subject->name, $start->format('d.m.Y'), $start->format('H:i'), $makeup->classroom->name), $makeup);

            LessonNotifier::rescheduled($s, $makeup);

            return $makeup;
        });
    }
}
