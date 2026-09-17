<?php

namespace App\Services\Accounting;

/**
 * Ondalık yardımcı (bcmath, float YOK).
 * Yuvarlama kuralı: kuruşa (2 hane) "yarım yukarı, sıfırdan uzağa" (1,235 → 1,24; -1,235 → -1,24).
 * Ara çarpım/bölmeler 10 hane ile yapılır, sonuç en sonda bir kez yuvarlanır.
 */
final class Dec
{
    public const WORK_SCALE = 10;

    public static function round(string $value, int $scale = 2): string
    {
        $value = self::norm($value);
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($abs, $half, $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0 ? '-'.$rounded : $rounded;
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::norm($a), self::norm($b), self::WORK_SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp(self::norm($b), '0', self::WORK_SCALE) === 0) {
            return '0';
        }

        return bcdiv(self::norm($a), self::norm($b), self::WORK_SCALE);
    }

    public static function add(string ...$values): string
    {
        return array_reduce($values, fn ($s, $v) => bcadd($s, self::norm($v), 2), '0.00');
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::norm($a), self::norm($b), 2);
    }

    public static function min(string $a, string $b): string
    {
        return bccomp(self::norm($a), self::norm($b), 2) <= 0 ? bcadd($a, '0', 2) : bcadd($b, '0', 2);
    }

    public static function max(string $a, string $b): string
    {
        return bccomp(self::norm($a), self::norm($b), 2) >= 0 ? bcadd($a, '0', 2) : bcadd($b, '0', 2);
    }

    public static function isZero(string $v): bool
    {
        return bccomp(self::norm($v), '0', 2) === 0;
    }

    public static function positive(string $v): bool
    {
        return bccomp(self::norm($v), '0', 2) > 0;
    }

    /** Veritabanından gelen null/sayı/string → bcmath uyumlu string. */
    public static function norm(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        $s = is_float($v) ? number_format($v, 10, '.', '') : trim((string) $v);

        return preg_match('/^-?\d+(\.\d+)?$/', $s) ? $s : '0';
    }
}
