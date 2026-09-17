<?php

namespace App\Services\Placement\Core;

/**
 * Alanlı şubeler için öğrenci kümeleri (saf, deterministik).
 *
 * Bir seviyenin şubeleri farklı alanlara (SAY/EA/…) ayrılmışsa her alan ayrı bir "kova" olur ve
 * yerleştirme motoru her kovada ayrı çalışır; böylece SAY öğrencisi SAY şubesine gider.
 *  - Sabitlenmiş öğrenci (ya da "yalnız sınıfsızlar" kipinde mevcut üye) bulunduğu şubenin kovasında kalır.
 *  - Alanı bir kovayla eşleşen öğrenci o kovaya, eşleşmeyen "genel" (alansız) kovaya gider.
 *  - Genel kova da yoksa: mevcut şubesi varsa onun kovası, yoksa en çok boş yeri kalan kova (uyarıyla).
 * Tüm şubeler aynı alandaysa tek kova döner (eski davranış).
 */
final class TrackPartitioner
{
    public const GENERAL = '';

    /**
     * @param  array<string, array{track:?string, capacity:int}>  $sections  şube => alan + kapasite (sıra korunur)
     * @param  array<int, array{field:?string, current:?string, pinned:bool}>  $students  öğrenci id =>
     * @return array{buckets: list<array{track:string, sections:list<string>, students:list<int>}>, warnings: list<string>}
     */
    public static function split(array $sections, array $students, string $mode = 'redistribute', array $trackLabels = []): array
    {
        $sectionBucket = [];
        $buckets = [];
        foreach ($sections as $code => $s) {
            $track = (string) ($s['track'] ?? self::GENERAL);
            $sectionBucket[(string) $code] = $track;
            $buckets[$track] ??= ['track' => $track, 'sections' => [], 'students' => [], 'capacity' => 0];
            $buckets[$track]['sections'][] = (string) $code;
            $buckets[$track]['capacity'] += (int) $s['capacity'];
        }
        ksort($students);

        if (count($buckets) <= 1) {
            $only = array_key_first($buckets);
            if ($only === null) {
                return ['buckets' => [], 'warnings' => []];
            }
            $buckets[$only]['students'] = array_map('intval', array_keys($students));

            return ['buckets' => [self::strip($buckets[$only])], 'warnings' => []];
        }

        $unmatched = [];
        foreach ($students as $id => $st) {
            $current = $st['current'] ?? null;
            $field = $st['field'] ?? null;
            if ($current !== null && isset($sectionBucket[$current]) && (($st['pinned'] ?? false) || $mode === 'unplaced')) {
                $buckets[$sectionBucket[$current]]['students'][] = (int) $id;
            } elseif ($field !== null && $field !== '' && isset($buckets[$field])) {
                $buckets[$field]['students'][] = (int) $id;
            } elseif (isset($buckets[self::GENERAL])) {
                $buckets[self::GENERAL]['students'][] = (int) $id;
            } else {
                $unmatched[(int) $id] = $st;
            }
        }

        $warnings = [];
        if ($unmatched) {
            $fields = [];
            foreach ($unmatched as $id => $st) {
                $current = $st['current'] ?? null;
                if ($current !== null && isset($sectionBucket[$current])) {
                    $target = $sectionBucket[$current];
                } else {
                    $target = null;
                    $bestFree = PHP_INT_MIN;
                    foreach ($buckets as $key => $b) {
                        $free = $b['capacity'] - count($b['students']);
                        if ($free > $bestFree) {
                            [$bestFree, $target] = [$free, $key];
                        }
                    }
                }
                $buckets[$target]['students'][] = $id;
                $f = $st['field'] ?? null;
                $fields[$f === null || $f === '' ? 'alanı girilmemiş' : ($trackLabels[$f] ?? $f)] = true;
            }
            $warnings[] = sprintf('%d öğrencinin alanına uygun şube yok (%s); mevcut şubesinde bırakıldı ya da boş yeri olan şubeye yerleştirildi.', count($unmatched), implode(', ', array_keys($fields)));
        }

        $out = [];
        foreach ($buckets as $b) {
            sort($b['students']);
            $out[] = self::strip($b);
        }

        return ['buckets' => $out, 'warnings' => $warnings];
    }

    private static function strip(array $b): array
    {
        return ['track' => $b['track'], 'sections' => $b['sections'], 'students' => $b['students']];
    }
}
