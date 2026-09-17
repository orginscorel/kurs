<?php

namespace App\Services\Automation;

/**
 * Belirleyici (deterministic) dedupe anahtarı üretir: aynı parçalar → aynı anahtar.
 * Kısa girdi olduğu gibi birleştirilir; uzun girdi kısaltılıp özet ile güvenceye alınır
 * (kolon sınırını aşmaz, çakışma ihtimali ihmal edilebilir düzeydedir).
 */
class DedupeKey
{
    /** @param list<scalar|null> $parts */
    public static function build(array $parts, int $maxLength = 120): string
    {
        $raw = implode(':', array_map(fn ($p) => (string) ($p ?? ''), $parts));

        if (mb_strlen($raw) <= $maxLength) {
            return $raw;
        }

        $hash = hash('sha256', $raw);
        $keep = max(0, $maxLength - 65);

        return mb_substr($raw, 0, $keep).':'.$hash;
    }
}
