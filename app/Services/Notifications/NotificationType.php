<?php

namespace App\Services\Notifications;

/**
 * `app_notifications.type` (varchar 20) üst çubuktaki NotificationCenter simge kategorisidir:
 * info | success | warning | critical | payment | academic | attendance | lesson.
 * Otomasyon tetikleyici adı (ör. `student.no_show_today`, 21 karakter) doğrudan yazılamaz.
 */
class NotificationType
{
    public const TYPES = ['info', 'success', 'warning', 'critical', 'payment', 'academic', 'attendance', 'lesson'];

    public static function forTrigger(string $trigger): string
    {
        return match (true) {
            str_starts_with($trigger, 'attendance.'), str_starts_with($trigger, 'student.') => 'attendance',
            str_starts_with($trigger, 'installment.'), str_starts_with($trigger, 'payment.'), str_starts_with($trigger, 'enrollment.') => 'payment',
            str_starts_with($trigger, 'exam.'), str_starts_with($trigger, 'homework.') => 'academic',
            str_starts_with($trigger, 'lesson.'), str_starts_with($trigger, 'schedule.') => 'lesson',
            str_starts_with($trigger, 'risk.') => 'critical',
            in_array($trigger, self::TYPES, true) => $trigger,
            default => 'info',
        };
    }
}
