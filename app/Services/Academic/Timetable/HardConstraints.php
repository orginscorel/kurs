<?php

namespace App\Services\Academic\Timetable;

/**
 * Sert kısıt doğrulayıcı — çözücüden BAĞIMSIZ, sıfırdan kontrol eder (çözücüye güvenmez).
 * Yalnızca en az biri yeni yerleşim olan çakışmalar sayılır (sabit–sabit eski çakışmalar botun sorumluluğu değil).
 */
final class HardConstraints
{
    /**
     * @param  array<int, array{0:int,1:int,2:int}>  $placements  ünite → [aralık, öğretmen, derslik]
     * @return list<string>
     */
    public static function violations(Problem $p, array $placements): array
    {
        $out = [];
        $byTeacher = [];
        $byRoom = [];
        $byClass = [];
        $load = [];
        $dayCount = [];

        foreach ($p->fixed as $f) {
            $entry = [$p->iWd[$f['i']], $p->iS[$f['i']], $p->iE[$f['i']], true, 'sabit ders'];
            if ($f['teacher'] !== null) {
                $byTeacher[$f['teacher']][] = $entry;
                $load[$f['teacher']] = ($load[$f['teacher']] ?? 0) + 1;
            }
            if ($f['room'] !== null) {
                $byRoom[$f['room']][] = $entry;
            }
            if ($f['class'] !== null) {
                $byClass[$f['class']][] = $entry;
                if ($f['subject'] !== null) {
                    $k = $f['class'].'|'.$p->iWd[$f['i']].'|'.$f['subject'];
                    $dayCount[$k] = ($dayCount[$k] ?? 0) + 1;
                }
            }
        }

        foreach ($placements as $u => [$i, $t, $r]) {
            $c = $p->unitClass[$u];
            $g = $p->unitGroup[$u];
            $label = ($p->classes[$c]['name'] ?? "#$c").' '.($p->subjects[$p->unitSubject[$u]]['name'] ?? '').' '.$p->label($i);

            if (! isset($p->classSlotSet[$c][$i])) {
                $out[] = "$label: sınıfın zaman şablonunda olmayan dilim.";
            }
            if (! in_array($t, $p->groupTeachers[$g], true)) {
                $out[] = "$label: öğretmen bu dersi veremez.";
            }
            if (! $p->avail($t, $i)) {
                $out[] = "$label: öğretmen uygunluğu dışında.";
            }
            if ((int) ($p->rooms[$r]['capacity'] ?? 0) < max(1, (int) ($p->classes[$c]['size'] ?? 1))) {
                $out[] = "$label: derslik kapasitesi yetersiz.";
            }
            if (($max = $p->groupMaxDay[$g] ?? null) !== null) {
                $k = $c.'|'.$p->iWd[$i].'|'.$p->unitSubject[$u];
                $dayCount[$k] = ($dayCount[$k] ?? 0) + 1;
                if ($dayCount[$k] === $max + 1) {
                    $out[] = "$label: sınıfın bu ders için günlük üst sınırı ($max saat) aşıldı.";
                }
            }
            $entry = [$p->iWd[$i], $p->iS[$i], $p->iE[$i], false, $label];
            $byTeacher[$t][] = $entry;
            $byRoom[$r][] = $entry;
            $byClass[$c][] = $entry;
            $load[$t] = ($load[$t] ?? 0) + 1;
        }

        foreach (['öğretmen' => $byTeacher, 'derslik' => $byRoom, 'sınıf' => $byClass] as $kind => $groups) {
            foreach ($groups as $list) {
                $n = count($list);
                for ($a = 0; $a < $n; $a++) {
                    for ($b = $a + 1; $b < $n; $b++) {
                        [$wa, $sa, $ea, $fa, $la] = $list[$a];
                        [$wb, $sb, $eb, $fb, $lb] = $list[$b];
                        if ($wa === $wb && $sa < $eb && $sb < $ea && ! ($fa && $fb)) {
                            $out[] = "$kind çakışması: $la ↔ $lb";
                        }
                    }
                }
            }
        }

        foreach ($load as $t => $n) {
            if (isset($p->tMax[$t]) && $n > $p->tMax[$t] && self::hasPlacementFor($placements, $t)) {
                $out[] = ($p->teachers[$t]['name'] ?? "#$t")." haftalık üst sınırı aştı ($n > {$p->tMax[$t]}).";
            }
        }

        return $out;
    }

    private static function hasPlacementFor(array $placements, int $t): bool
    {
        foreach ($placements as [$i, $pt]) {
            if ($pt === $t) {
                return true;
            }
        }

        return false;
    }
}
