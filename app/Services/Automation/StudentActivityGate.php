<?php

namespace App\Services\Automation;

/**
 * Ayrılan / donan / mezun öğrenciye otomasyon mesajı gitmez. İstisna: gerçekleşmiş bir işlemin
 * bilgisi (tahsilat makbuzu) — para alındıysa makbuz bilgisi yine gönderilebilir.
 */
class StudentActivityGate
{
    public const INACTIVE_STATUSES = ['withdrawn', 'frozen', 'graduated'];

    public const ALWAYS_ALLOWED_TRIGGERS = ['payment.received'];

    public static function allows(string $trigger, ?string $studentStatus): bool
    {
        if (in_array($trigger, self::ALWAYS_ALLOWED_TRIGGERS, true)) {
            return true;
        }

        return ! in_array($studentStatus, self::INACTIVE_STATUSES, true);
    }

    public static function isInactive(?string $status): bool
    {
        return in_array($status, self::INACTIVE_STATUSES, true);
    }
}
