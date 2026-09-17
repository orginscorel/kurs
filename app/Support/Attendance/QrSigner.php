<?php

namespace App\Support\Attendance;

use Illuminate\Support\Str;

/**
 * Öğrenci QR kimliği için saf imzalama mantığı (framework/DB bağımsız — test edilebilir).
 * Format: EBEQ1.<student_id>.<rastgele>.<imza(16 hex)>
 */
final class QrSigner
{
    public const PREFIX = 'EBEQ1';

    public static function generate(int $studentId, string $secret): string
    {
        $value = self::PREFIX.'.'.$studentId.'.'.Str::random(24);

        return $value.'.'.self::sign($value, $secret);
    }

    public static function sign(string $value, string $secret): string
    {
        return substr(hash_hmac('sha256', $value, $secret), 0, 16);
    }

    public static function verify(string $signedValue, string $secret): bool
    {
        $parts = explode('.', $signedValue);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            return false;
        }
        $value = implode('.', array_slice($parts, 0, 3));

        return hash_equals(self::sign($value, $secret), $parts[3]);
    }

    public static function studentId(string $signedValue): ?int
    {
        $parts = explode('.', $signedValue);

        return count($parts) === 4 && $parts[0] === self::PREFIX && ctype_digit($parts[1]) ? (int) $parts[1] : null;
    }
}
