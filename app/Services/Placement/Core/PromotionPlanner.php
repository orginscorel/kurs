<?php

namespace App\Services\Placement\Core;

/**
 * Dönem sonu seviye atlatma planı (saf): 9→10, 10→11, 11→12 (şube harfi korunur), 12 → Mezun.
 * Yalnız aktif öğrenciler atlatılır; diğerleri gerekçesiyle atlanır.
 */
final class PromotionPlanner
{
    /**
     * @param  list<array{id:int, grade:?int, section:?string, status:string}>  $students
     * @param  list<int>  $levels  ör. [9,10,11,12]
     * @param  array<string,int>  $capacities  "10-A" => 15 (hedef dönemdeki sınıflar)
     * @param  array<string,int>  $existing  "10-A" => hedef dönemde zaten kayıtlı öğrenci sayısı
     * @return array{promote: list<array{id:int, from:int, to:int, section:?string}>, graduate: list<int>, skipped: list<array{id:int, reason:string}>, targets: array<string,int>, overflow: list<array{id:int, class:string}>}
     */
    public function plan(array $students, array $levels, array $capacities = [], array $existing = []): array
    {
        sort($levels);
        $max = end($levels);
        $promote = $graduate = $skipped = $overflow = [];
        $targets = $existing;

        usort($students, fn ($a, $b) => $a['id'] <=> $b['id']);
        foreach ($students as $s) {
            if ($s['status'] !== 'active') {
                $skipped[] = ['id' => $s['id'], 'reason' => 'Aktif değil'];
                continue;
            }
            if ($s['grade'] === null || ! in_array($s['grade'], $levels, true)) {
                $skipped[] = ['id' => $s['id'], 'reason' => 'Sınıf seviyesi tanımsız'];
                continue;
            }
            if ($s['grade'] === $max) {
                $graduate[] = $s['id'];
                continue;
            }
            $to = $s['grade'] + 1;
            $section = $s['section'];
            if ($section !== null && $capacities !== [] && ! array_key_exists("{$to}-{$section}", $capacities)) {
                // Üst seviyede bu şube yok (ör. şubesiz seviye): sınıfsız geçer, yerleştirme botuna bırakılır
                $section = null;
            }
            if ($section !== null) {
                $key = "{$to}-{$section}";
                $cap = $capacities[$key] ?? null;
                if ($cap !== null && ($targets[$key] ?? 0) >= $cap) {
                    // Hedef sınıf dolu: seviye atlar ama sınıfsız kalır (yerleştirme botuna bırakılır)
                    $overflow[] = ['id' => $s['id'], 'class' => $key];
                    $section = null;
                } else {
                    $targets[$key] = ($targets[$key] ?? 0) + 1;
                }
            }
            $promote[] = ['id' => $s['id'], 'from' => $s['grade'], 'to' => $to, 'section' => $section];
        }
        ksort($targets);

        return compact('promote', 'graduate', 'skipped', 'targets', 'overflow');
    }
}
