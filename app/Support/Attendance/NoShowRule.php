<?php

namespace App\Support\Attendance;

use Carbon\CarbonImmutable;

/** "Bugün kuruma gelmedi" eşiği: saf zaman mantığı (test edilebilir). */
final class NoShowRule
{
    public static function shouldFlag(CarbonImmutable $firstLessonStart, int $delayMinutes, CarbonImmutable $now): bool
    {
        return $now->gte($firstLessonStart->addMinutes($delayMinutes));
    }
}
