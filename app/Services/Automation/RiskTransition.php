<?php

namespace App\Services\Automation;

/** Risk seviyesi geçiş algılama: yalnız "high olmayan → high" geçişi eylem üretir (her gece tekrar değil). */
class RiskTransition
{
    private const ORDER = ['low' => 0, 'medium' => 1, 'high' => 2];

    public static function escalatedToHigh(?string $previous, ?string $current): bool
    {
        return $current === 'high' && $previous !== 'high';
    }

    public static function isEscalation(?string $previous, ?string $current): bool
    {
        if ($current === null || ! isset(self::ORDER[$current])) {
            return false;
        }

        return self::ORDER[$current] > (self::ORDER[$previous] ?? -1);
    }
}
