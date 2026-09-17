<?php

namespace App\Services\Academic;

use Carbon\CarbonImmutable;

/**
 * Saf zaman-aralığı matematiği (veritabanı yok): dakika dönüşümü, çakışma, boşluk çıkarma, doluluk.
 * Aralıklar [başlangıç, bitiş) dakika çiftleridir; uç uca aralıklar çakışmaz.
 */
final class TimeSlots
{
    public const WEEKDAYS = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];

    /** 'HH:MM' ya da 'HH:MM:SS' → gün içi dakika */
    public static function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, 0));

        return $h * 60 + $m;
    }

    /** dakika → 'HH:MM' */
    public static function toTime(int $minutes): string
    {
        $minutes = max(0, min(24 * 60 - 1, $minutes));

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** 'HH:MM' → 'HH:MM:SS' (veritabanı TIME kolonu) */
    public static function normalize(string $time): string
    {
        return self::toTime(self::toMinutes($time)).':00';
    }

    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /**
     * Uygunluk pencerelerinden dolu aralıkları çıkarır; kalan boşlukları döner.
     *
     * @param  list<array{0:int,1:int}>  $windows  uygun pencereler
     * @param  list<array{0:int,1:int}>  $busy  dolu aralıklar
     * @return list<array{0:int,1:int}> minLength dakikadan kısa boşluklar atılır
     */
    public static function subtract(array $windows, array $busy, int $minLength = 0): array
    {
        $result = [];
        usort($busy, fn ($a, $b) => $a[0] <=> $b[0]);

        foreach ($windows as [$start, $end]) {
            $cursor = $start;
            foreach ($busy as [$bStart, $bEnd]) {
                if ($bEnd <= $cursor || $bStart >= $end) {
                    continue;
                }
                if ($bStart > $cursor) {
                    $result[] = [$cursor, $bStart];
                }
                $cursor = max($cursor, $bEnd);
                if ($cursor >= $end) {
                    break;
                }
            }
            if ($cursor < $end) {
                $result[] = [$cursor, $end];
            }
        }

        return array_values(array_filter($result, fn ($r) => $r[1] - $r[0] >= $minLength));
    }

    /** Birbirine değen/çakışan aralıkları birleştirir (doluluk hesabı için). */
    public static function merge(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [$ranges[0]];
        foreach (array_slice($ranges, 1) as [$s, $e]) {
            $last = &$out[count($out) - 1];
            if ($s <= $last[1]) {
                $last[1] = max($last[1], $e);
            } else {
                $out[] = [$s, $e];
            }
            unset($last);
        }

        return $out;
    }

    /** Doluluk yüzdesi (0–100, bir ondalık). */
    public static function occupancy(int $usedMinutes, int $availableMinutes): float
    {
        if ($availableMinutes <= 0) {
            return 0.0;
        }

        return round(min(100, $usedMinutes / $availableMinutes * 100), 1);
    }

    /** Verilen tarihin ISO haftasının pazartesisi. */
    public static function weekStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfDay()->subDays($date->dayOfWeekIso - 1);
    }
}
