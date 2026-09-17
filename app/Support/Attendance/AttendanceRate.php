<?php

namespace App\Support\Attendance;

/** Devam oranı ve eşik hesapları: saf sayı mantığı (test edilebilir). */
final class AttendanceRate
{
    /** (Var + Geç) / Toplam, yüzde, tam sayıya yuvarlanır. */
    public static function rate(int $present, int $late, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round(($present + $late) / $total * 100);
    }

    /** Verilen "yok" sayısı eşiği aşıyor mu (ör. 30 günde >= 5 yok). */
    public static function overThreshold(int $absentCount, int $threshold): bool
    {
        return $absentCount >= max(1, $threshold);
    }
}
