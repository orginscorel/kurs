<?php

namespace App\Services\Academic\Timetable;

/**
 * Ders programı problemi — veritabanından BAĞIMSIZ, saf veri. Saatler gün içi dakikadır.
 *
 * Girdi:
 *  classes[id]  = ['name', 'size', 'homeroom' => ?roomId, 'slots' => [[weekday, start, end], …]]
 *  subjects[id] = ['name', 'hard' => bool]
 *  teachers[id] = ['name', 'subjects' => [subjectId…], 'max_units' => int, 'target' => ?int (haftalık hedef, yumuşak),
 *                  'availability' => null | [weekday => [[start, end], …]], 'blocked_days' => [weekday…]]
 *  rooms[id]    = ['name', 'capacity']
 *  demands      = [['class', 'subject', 'hours', 'teachers' => null | [teacherId…], 'strict' => bool (liste branştan bağımsız, ör. sabit öğretmen),
 *                   'max_per_day' => ?int (sert günlük üst sınır), 'block' => ?int (2 = ikişer saatlik blok tercihi)], …]
 *  fixed        = [['class' => ?, 'subject' => ?, 'teacher' => ?, 'room' => ?, 'weekday', 'start', 'end'], …]
 *                 (seçilmeyen sınıfların programı + kilitli dersler: sert kısıt olarak sabit)
 *
 * Her "ünite" bir ders saatidir (sınıfın şablonundaki bir dilim). Aynı sınıf+ders üniteleri bir "grup"tur.
 */
final class Problem
{
    // ---- aralıklar
    /** @var list<int> */ public array $iWd = [];
    /** @var list<int> */ public array $iS = [];
    /** @var list<int> */ public array $iE = [];
    /** @var array<int, list<int>> i → çakışan aralıklar (kendisi dahil) */ public array $overlap = [];

    // ---- üniteler / gruplar
    /** @var list<int> */ public array $unitClass = [];
    /** @var list<int> */ public array $unitSubject = [];
    /** @var list<int> */ public array $unitGroup = [];
    /** @var list<int> */ public array $groupClass = [];
    /** @var list<int> */ public array $groupSubject = [];
    /** @var list<list<int>> */ public array $groupUnits = [];
    /** @var list<list<int>> */ public array $groupTeachers = [];
    /** @var list<?int> sert günlük üst sınır */ public array $groupMaxDay = [];
    /** @var list<int> 1 | 2 (blok) */ public array $groupBlock = [];
    /** @var array<int, array<int,int>> sınıf → ders → grup */ public array $classSubjectGroup = [];

    // ---- sınıflar
    /** @var array<int, list<int>> */ public array $classSlots = [];
    /** @var array<int, array<int,bool>> */ public array $classSlotSet = [];
    /** @var array<int, array<int,int>> sınıf → aralık → gün içi sıra */ public array $slotIdx = [];
    /** @var array<int, array<int,float>> sınıf → aralık → 0..1 konum */ public array $slotPos = [];
    /** @var array<int, list<int>> kapasitesi yeten derslikler (ana derslik önce, sonra küçükten büyüğe) */ public array $classRooms = [];
    /** @var array<int, array<int,bool>> */ public array $classRoomSet = [];
    /** @var array<int, ?int> */ public array $homeroom = [];

    // ---- öğretmenler
    /** @var array<int,int> */ public array $tMax = [];
    /** @var array<int,?int> haftalık hedef saat */ public array $tTarget = [];
    /** @var array<int, array<int,bool>> önbellek */ private array $availCache = [];

    // ---- sabitler (seçilmeyen sınıflar, kilitli dersler)
    /** @var list<array{class:?int, subject:?int, teacher:?int, room:?int, i:int}> */ public array $fixed = [];

    /** @var list<int> programdaki farklı günler */ public array $days = [];

    /** @var array<string,float> */ public array $weights;

    /** @var array<string, int> */ private array $intervalIndex = [];

    public function __construct(
        public readonly array $classes,
        public readonly array $subjects,
        public readonly array $teachers,
        public readonly array $rooms,
        public readonly array $demands,
        array $fixed = [],
        array $weights = [],
        public readonly int $maxPerDay = 2,
    ) {
        $this->weights = Weights::normalize($weights);

        foreach ($classes as $c => $cls) {
            $slots = [];
            foreach ($cls['slots'] ?? [] as [$wd, $s, $e]) {
                if ($e > $s) {
                    $slots[$this->interval((int) $wd, (int) $s, (int) $e)] = true;
                }
            }
            $ids = array_keys($slots);
            usort($ids, fn ($a, $b) => [$this->iWd[$a], $this->iS[$a]] <=> [$this->iWd[$b], $this->iS[$b]]);
            $this->classSlots[$c] = $ids;
            $this->classSlotSet[$c] = $slots;

            $byDay = [];
            foreach ($ids as $i) {
                $byDay[$this->iWd[$i]][] = $i;
            }
            foreach ($byDay as $wd => $list) {
                $n = count($list);
                foreach ($list as $k => $i) {
                    $this->slotIdx[$c][$i] = $k;
                    $this->slotPos[$c][$i] = $n > 1 ? $k / ($n - 1) : 0.0;
                }
                $this->days[$wd] = true;
            }

            $size = max(1, (int) ($cls['size'] ?? 1));
            $fit = array_keys(array_filter($rooms, fn ($r) => (int) $r['capacity'] >= $size));
            usort($fit, fn ($a, $b) => (int) $rooms[$a]['capacity'] <=> (int) $rooms[$b]['capacity'] ?: $a <=> $b);
            $home = isset($cls['homeroom']) && in_array((int) $cls['homeroom'], $fit, true) ? (int) $cls['homeroom'] : null;
            if ($home !== null) {
                $fit = array_values(array_merge([$home], array_diff($fit, [$home])));
            }
            $this->classRooms[$c] = $fit;
            $this->classRoomSet[$c] = array_fill_keys($fit, true);
            $this->homeroom[$c] = $home;
        }
        $this->days = array_keys($this->days);
        sort($this->days);

        foreach ($teachers as $t => $teacher) {
            $this->tMax[$t] = max(0, (int) ($teacher['max_units'] ?? 40));
            $this->tTarget[$t] = isset($teacher['target']) && (int) $teacher['target'] > 0 ? min((int) $teacher['target'], $this->tMax[$t]) : null;
        }

        foreach ($demands as $d) {
            $c = (int) $d['class'];
            $s = (int) $d['subject'];
            $hours = (int) $d['hours'];
            if ($hours <= 0 || ! isset($classes[$c])) {
                continue;
            }
            if (isset($this->classSubjectGroup[$c][$s])) {
                continue; // aynı sınıf+ders iki kez verilmiş: ilki geçerli
            }
            $g = count($this->groupClass);
            $this->groupClass[$g] = $c;
            $this->groupSubject[$g] = $s;
            $this->classSubjectGroup[$c][$s] = $g;
            $this->groupMaxDay[$g] = isset($d['max_per_day']) && (int) $d['max_per_day'] > 0 ? (int) $d['max_per_day'] : null;
            $this->groupBlock[$g] = (int) ($d['block'] ?? 1) === 2 ? 2 : 1;
            $allowed = $d['teachers'] ?? null;
            $strict = (bool) ($d['strict'] ?? false) && $allowed !== null;
            $cands = [];
            foreach ($teachers as $t => $teacher) {
                $competent = $strict || in_array($s, array_map('intval', $teacher['subjects'] ?? []), true);
                if ($competent && ($allowed === null || in_array($t, $allowed, false))) {
                    $cands[] = (int) $t;
                }
            }
            $this->groupTeachers[$g] = $cands;
            $this->groupUnits[$g] = [];
            for ($k = 0; $k < $hours; $k++) {
                $u = count($this->unitClass);
                $this->unitClass[$u] = $c;
                $this->unitSubject[$u] = $s;
                $this->unitGroup[$u] = $g;
                $this->groupUnits[$g][] = $u;
            }
        }

        foreach ($fixed as $f) {
            if (($f['end'] ?? 0) <= ($f['start'] ?? 0)) {
                continue;
            }
            $this->fixed[] = [
                'class' => isset($f['class']) && isset($classes[$f['class']]) ? (int) $f['class'] : null,
                'subject' => isset($f['subject']) ? (int) $f['subject'] : null,
                'teacher' => isset($f['teacher']) ? (int) $f['teacher'] : null,
                'room' => isset($f['room']) ? (int) $f['room'] : null,
                'i' => $this->interval((int) $f['weekday'], (int) $f['start'], (int) $f['end']),
            ];
        }

        $this->buildOverlaps();
    }

    /** Yumuşak günlük üst sınır: sınıfa özel sınır > blok (en az 2) > genel ayar. */
    public function softMaxDay(int $g): int
    {
        return $this->groupMaxDay[$g] ?? ($this->groupBlock[$g] === 2 ? max(2, $this->maxPerDay) : $this->maxPerDay);
    }

    public function unitCount(): int
    {
        return count($this->unitClass);
    }

    public function groupCount(): int
    {
        return count($this->groupClass);
    }

    /** Öğretmen bu aralıkta uygun mu (uygunluk penceresi + kapalı gün). Uygunluk tanımsızsa her saat uygun. */
    public function avail(int $t, int $i): bool
    {
        if (isset($this->availCache[$t][$i])) {
            return $this->availCache[$t][$i];
        }
        $teacher = $this->teachers[$t] ?? null;
        $ok = $teacher !== null;
        $wd = $this->iWd[$i];
        if ($ok && in_array($wd, array_map('intval', $teacher['blocked_days'] ?? []), true)) {
            $ok = false;
        }
        if ($ok && ($teacher['availability'] ?? null) !== null) {
            $ok = false;
            foreach ($teacher['availability'][$wd] ?? [] as [$s, $e]) {
                if ($s <= $this->iS[$i] && $e >= $this->iE[$i]) {
                    $ok = true;
                    break;
                }
            }
        }

        return $this->availCache[$t][$i] = $ok;
    }

    public function label(int $i): string
    {
        return self::WEEKDAYS[$this->iWd[$i]].' '.self::time($this->iS[$i]);
    }

    public const WEEKDAYS = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];

    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, 0));

        return $h * 60 + $m;
    }

    private function interval(int $wd, int $s, int $e): int
    {
        $key = "$wd:$s:$e";
        if (! isset($this->intervalIndex[$key])) {
            $id = count($this->iWd);
            $this->iWd[] = $wd;
            $this->iS[] = $s;
            $this->iE[] = $e;
            $this->intervalIndex[$key] = $id;
        }

        return $this->intervalIndex[$key];
    }

    private function buildOverlaps(): void
    {
        $byDay = [];
        foreach ($this->iWd as $i => $wd) {
            $byDay[$wd][] = $i;
        }
        foreach ($byDay as $list) {
            foreach ($list as $a) {
                $this->overlap[$a] = [];
                foreach ($list as $b) {
                    if ($this->iS[$a] < $this->iE[$b] && $this->iS[$b] < $this->iE[$a]) {
                        $this->overlap[$a][] = $b;
                    }
                }
            }
        }
    }
}
