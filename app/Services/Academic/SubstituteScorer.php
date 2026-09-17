<?php

namespace App\Services\Academic;

/**
 * Yedek öğretmen puanlaması (saf, veritabanı yok). 0–100; yüksek = daha uygun.
 * Sert eleme (dolu, izinli, uygunluk dışı, haftalık üst sınır) çağıran tarafta yapılır.
 */
final class SubstituteScorer
{
    /**
     * @param  array{competent:bool, day_load:int, week_load:int, max_week:int, adjacent:bool, knows_class:bool}  $f
     * @return array{score:int, reasons:list<string>}
     */
    public static function score(array $f): array
    {
        $score = 55.0;
        $reasons = [];

        if ($f['competent']) {
            $score += 25;
            $reasons[] = 'Branşı uyuyor';
        } else {
            $score -= 30;
            $reasons[] = 'Branş dışı';
        }
        if ($f['knows_class']) {
            $score += 10;
            $reasons[] = 'Bu sınıfın derslerine giriyor';
        }
        if ($f['adjacent']) {
            $score += 6;
            $reasons[] = 'Hemen öncesinde/sonrasında dersi var';
        }

        $day = max(0, $f['day_load']);
        $score -= 6 * $day;
        $reasons[] = $day === 0 ? 'O gün başka dersi yok' : "O gün $day dersi var";

        $max = max(1, $f['max_week']);
        $ratio = min(1.0, $f['week_load'] / $max);
        $score -= 20 * $ratio;
        if ($ratio >= 0.85) {
            $reasons[] = "Haftalık yükü dolmak üzere ({$f['week_load']}/{$max})";
        }

        return ['score' => (int) round(max(0, min(100, $score))), 'reasons' => $reasons];
    }

    /** @param list<array{score:int, competent:bool}> $candidates */
    public static function sort(array $candidates): array
    {
        usort($candidates, fn ($a, $b) => [$b['competent'], $b['score']] <=> [$a['competent'], $a['score']]);

        return $candidates;
    }
}
