<?php

namespace App\Support\Guidance;

/**
 * Hedef vs gerçek karşılaşırması — saf hesap (DB'ye dokunmaz).
 * "actual" listesi en güncelden en eskiye sıralı sınav net değerleridir; ilk N'i ortalanır.
 */
final class GoalProgress
{
    /** @param list<float> $nets en yeniden en eskiye sıralı */
    public static function average(array $nets, int $take = 3): ?float
    {
        $slice = array_slice(array_values($nets), 0, $take);
        if ($slice === []) {
            return null;
        }

        return round(array_sum($slice) / count($slice), 2);
    }

    /** Hedefe göre yüzde ilerleme (0-100+ arası; hedef yoksa null). */
    public static function percentage(?float $target, ?float $actual): ?float
    {
        if ($target === null || $target <= 0 || $actual === null) {
            return null;
        }

        return round(min(999, max(0, $actual / $target * 100)), 1);
    }

    /** @return array{target: ?float, actual: ?float, pct: ?float, diff: ?float} */
    public static function compare(?float $target, ?float $actual): array
    {
        return [
            'target' => $target,
            'actual' => $actual,
            'pct' => self::percentage($target, $actual),
            'diff' => ($target !== null && $actual !== null) ? round($actual - $target, 2) : null,
        ];
    }
}
