<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;

/**
 * Para: her zaman 2 haneli ondalık STRING. Kayan nokta kullanılmaz.
 */
final class Money
{
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        $s = is_float($value) ? number_format($value, 2, '.', '') : trim((string) $value);

        // "5.000,50" (Türkçe) → "5000.50"
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s) || str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }

        if (! preg_match('/^-?\d+(\.\d{1,})?$/', $s)) {
            throw new BusinessRuleException('Geçersiz tutar.', 'invalid_amount');
        }

        return bcadd($s, '0', 2);
    }

    public static function format(string|int|float|null $value): string
    {
        return number_format((float) self::of($value), 2, ',', '.');
    }

    public static function isPositive(string $value): bool
    {
        return bccomp($value, '0', 2) > 0;
    }

    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? $a : $b;
    }
}
