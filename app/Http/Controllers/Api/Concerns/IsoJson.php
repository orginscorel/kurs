<?php

namespace App\Http\Controllers\Api\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Portal yanıtları: "2026-09-16 18:30:00" biçimli tarih-saatler ISO'ya ("2026-09-16T18:30:00") çevrilir
 * (iOS Safari boşluklu biçimi çözemiyor).
 */
trait IsoJson
{
    protected function isoJson(mixed $payload, int $status = 200): JsonResponse
    {
        $data = json_decode(json_encode($payload), true);
        if (is_array($data)) {
            array_walk_recursive($data, function (&$v) {
                if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
                    $v = str_replace(' ', 'T', $v);
                }
            });
        }

        return response()->json($data, $status);
    }

    protected function parseDay(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
