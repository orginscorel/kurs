<?php

namespace App\Services\Automation;

/**
 * Sınav sonucu yayımlanınca "düşüş" tespiti: aynı sınav türündeki bir önceki yayımlı sonuca göre
 * en az 5 net VE en az %10 düşüş (düşük netli öğrencide 1-2 netlik oynamalar alarm üretmesin).
 */
class NetDrop
{
    public const MIN_ABSOLUTE = 5.0;

    public const MIN_RATIO = 0.10;

    public static function isSignificant(?float $previous, float $current): bool
    {
        if ($previous === null || $previous <= 0) {
            return false;
        }
        $drop = $previous - $current;

        return $drop >= self::MIN_ABSOLUTE && ($drop / $previous) >= self::MIN_RATIO;
    }

    public static function delta(?float $previous, float $current): ?float
    {
        return $previous === null ? null : round($current - $previous, 2);
    }
}
