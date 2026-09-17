<?php

namespace App\Services\Academic;

use App\Models\LessonSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Haftalık şablonlardan somut ders oturumları üretir. Tekrar çalıştırmak güvenlidir:
 * (lesson_schedule_id, date) tekil olduğu için var olan oturum (ve üzerindeki yoklama,
 * iptal, derslik değişikliği) korunur.
 */
class SessionGenerator
{
    public function generate(int $branchId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $schedules = LessonSchedule::query()
            ->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)
            ->where('valid_from', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $from->toDateString()))
            ->get();

        // Tatile denk gelen oturumlar gerekçesiyle İPTAL durumunda üretilir (tatil silinirse geri alınır).
        $holidays = new HolidayCalendar(DB::table('holidays')->where('branch_id', $branchId)->where('cancel_sessions', true)
            ->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())
            ->get(['id', 'name', 'starts_on', 'ends_on'])->map(fn ($h) => (array) $h)->all());

        $rows = [];
        $now = now();

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = CarbonImmutable::instance($day);
            $holiday = $holidays->find($date->toDateString());
            foreach ($schedules->where('weekday', $date->dayOfWeekIso) as $s) {
                if ($date->lt($s->valid_from) || ($s->valid_until && $date->gt($s->valid_until))) {
                    continue;
                }
                $rows[] = [
                    'branch_id' => $branchId,
                    'lesson_schedule_id' => $s->id,
                    'class_group_id' => $s->class_group_id,
                    'subject_id' => $s->subject_id,
                    'teacher_id' => $s->teacher_id,
                    'classroom_id' => $s->classroom_id,
                    'date' => $date->toDateString(),
                    'starts_at' => $date->toDateString().' '.$s->starts_at,
                    'ends_at' => $date->toDateString().' '.$s->ends_at,
                    'status' => $holiday ? 'cancelled' : 'scheduled',
                    'cancel_reason' => $holiday ? HolidayCalendar::reason($holiday) : null,
                    'holiday_id' => $holiday['id'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += DB::table('lesson_sessions')->insertOrIgnore($chunk);
        }

        return $inserted;
    }
}
