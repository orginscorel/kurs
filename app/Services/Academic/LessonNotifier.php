<?php

namespace App\Services\Academic;

use App\Models\ClassGroup;
use App\Models\Holiday;
use App\Models\LessonSession;
use App\Services\Automation\AutomationEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ders oturumu değişikliklerini öğrenci/veli bildirimine çevirir (AutomationEngine üzerinden).
 * Transaction içinde çağrılsa bile olaylar COMMIT sonrası atılır; bildirim hatası asıl işlemi bozmaz.
 *
 * Tetikleyiciler: lesson.cancelled (şablon: lesson.cancelled), lesson.room_changed (şablon: lesson.room_changed).
 * lesson.rescheduled ve lesson.teacher_changed de atılır; ilgili kural tanımlanana kadar etkisizdir.
 */
class LessonNotifier
{
    public static function cancelled(LessonSession $s, string $reason): void
    {
        self::forSession('lesson.cancelled', $s, ['aciklama' => $reason], "lesson_cancel:{$s->id}");
    }

    public static function roomChanged(LessonSession $s): void
    {
        self::forSession('lesson.room_changed', $s, [], "lesson_room:{$s->id}:{$s->classroom_id}");
    }

    public static function teacherChanged(LessonSession $s): void
    {
        self::forSession('lesson.teacher_changed', $s, [], "lesson_teacher:{$s->id}:{$s->teacher_id}");
    }

    /** Telafi: eski ders iptal bildirimi (açıklamada yeni zaman) + yeni zaman için ayrı tetikleyici. */
    public static function rescheduled(LessonSession $original, LessonSession $makeup): void
    {
        $new = CarbonImmutable::parse($makeup->starts_at);
        $room = $makeup->classroom?->name ?? DB::table('classrooms')->where('id', $makeup->classroom_id)->value('name');
        $text = sprintf('Telafi dersi %s %s saat %s, %s.', $new->format('d.m.Y'), TimeSlots::WEEKDAYS[$new->dayOfWeekIso], $new->format('H:i'), $room);
        self::forSession('lesson.cancelled', $original, ['aciklama' => $text], "lesson_cancel:{$original->id}");
        self::forSession('lesson.rescheduled', $makeup, ['aciklama' => $text, 'eski_tarih' => $original->date->format('d.m.Y')], "lesson_makeup:{$makeup->id}");
    }

    /** Tatil: etkilenen her öğrenciye TEK bildirim (ders başına değil). */
    public static function holiday(Holiday $h, array $classGroupIds): void
    {
        $range = $h->starts_on->equalTo($h->ends_on) ? $h->starts_on->format('d.m.Y') : $h->starts_on->format('d.m').'–'.$h->ends_on->format('d.m.Y');
        $vars = ['tarih' => $range, 'ders_saati' => '', 'ders_adi' => 'Tüm dersler', 'derslik' => '', 'aciklama' => "{$h->name} nedeniyle bu tarihlerde ders yapılmayacaktır."];
        DB::afterCommit(function () use ($h, $classGroupIds, $vars) {
            self::guard(function () use ($h, $classGroupIds, $vars) {
                $seen = [];
                foreach (ClassGroup::query()->whereIn('id', $classGroupIds)->get() as $group) {
                    foreach ($group->activeStudents()->get() as $student) {
                        if (isset($seen[$student->id])) {
                            continue;
                        }
                        $seen[$student->id] = true;
                        AutomationEngine::fire('lesson.cancelled', $student, $vars, ['class_group_id' => $group->id, 'program_id' => $group->program_id, 'dedupe_suffix' => "holiday:{$h->id}:{$h->starts_on->toDateString()}"]);
                    }
                }
            });
        });
    }

    private static function forSession(string $trigger, LessonSession $s, array $extra, string $dedupe): void
    {
        $s->loadMissing(['subject:id,name', 'classroom:id,name', 'classGroup:id,name,program_id', 'teacher:id,first_name,last_name']);
        $start = CarbonImmutable::parse($s->starts_at);
        $vars = [
            'tarih' => $start->format('d.m.Y'), 'ders_saati' => $start->format('H:i'), 'ders_adi' => $s->subject?->name ?? '',
            'derslik' => $s->classroom?->name ?? '', 'ogretmen' => $s->teacher?->full_name ?? '', 'aciklama' => '',
        ] + $extra;
        $vars = array_merge($vars, $extra);
        $groupId = $s->class_group_id;
        $programId = $s->classGroup?->program_id;

        DB::afterCommit(function () use ($trigger, $groupId, $programId, $vars, $dedupe) {
            self::guard(function () use ($trigger, $groupId, $programId, $vars, $dedupe) {
                $group = ClassGroup::query()->find($groupId);
                foreach ($group?->activeStudents()->get() ?? [] as $student) {
                    AutomationEngine::fire($trigger, $student, $vars, ['class_group_id' => $groupId, 'program_id' => $programId, 'dedupe_suffix' => $dedupe]);
                }
            });
        });
    }

    private static function guard(callable $fn): void
    {
        if (! class_exists(AutomationEngine::class)) {
            return;
        }
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Ders bildirimi tetiklenemedi', ['error' => $e->getMessage()]);
        }
    }
}
