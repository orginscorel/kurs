<?php

namespace App\Services\Academic\Timetable;

/**
 * Çözümün değişken durumu: yerleşimler, doluluk tabloları ve ARTIMSAL yumuşak ceza.
 * place()/unplace() yalnızca etkilenen anahtarları (sınıf-gün, öğretmen-gün, öğretmen, ünite, grup)
 * kirli işaretler; refresh() onları yeniden hesaplayıp toplamı günceller.
 */
final class State
{
    /** @var list<int> */ public array $aI;
    /** @var list<int> */ public array $aT;
    /** @var list<int> */ public array $aR;
    public int $placed = 0;

    /** @var array<int,array<int,array<int,true>>> varlık → aralık → ünite kümesi */
    private array $tU = [];
    private array $rU = [];
    private array $cU = [];
    /** @var array<int,array<int,int>> sabit doluluk sayacı */
    private array $tF = [];
    private array $rF = [];
    private array $cF = [];

    /** @var array<int,int> */ public array $tLoad = [];
    /** @var array<int,array<int,int>> öğretmen → gün → sabit ders sayısı */ private array $tFixedDay = [];
    /** @var array<int,array<int,list<array{int,int}>>> öğretmen → gün → sabit aralıklar */ private array $tFixedIv = [];
    /** @var array<int,array<int,list<array{int,int}>>> sınıf → gün → [ders, aralık] (kilitli) */ private array $cFixedDay = [];

    /** @var array<int,array<int,array<int,int>>> sınıf → gün → ders → yerleşik saat */ private array $csd = [];
    /** @var array<int,array<int,array<int,int>>> sınıf → gün → ders → sabit (kilitli) saat */ private array $cFixedSubj = [];
    /** @var array<string,float> yalnız sabit derslerden gelen (botun değiştiremediği) ham ceza */ public array $baseRaw = [];
    public float $baseTotal = 0.0;

    /** @var array<int,array<int,array<int,true>>> */ private array $cdUnits = [];
    /** @var array<int,array<int,array<int,true>>> */ private array $tdUnits = [];
    /** @var array<int,array<int,int>> grup → öğretmen → ünite sayısı */ private array $gT = [];

    private array $cacheCD = [];
    private array $cacheTD = [];
    private array $cacheTB = [];
    private array $cacheU = [];
    private array $cacheG = [];
    private array $dirtyCD = [];
    private array $dirtyTD = [];
    private array $dirtyTB = [];
    private array $dirtyU = [];
    private array $dirtyG = [];

    public float $total = 0.0;

    private readonly array $w;
    private readonly int $dayCount;

    public function __construct(public readonly Problem $p)
    {
        $n = $p->unitCount();
        $this->aI = $n ? array_fill(0, $n, -1) : [];
        $this->aT = $this->aI;
        $this->aR = $this->aI;
        $this->w = $p->weights;
        $this->dayCount = max(1, count($p->days));

        foreach ($p->tMax as $t => $_) {
            $this->tLoad[$t] = 0;
        }
        foreach ($p->fixed as $f) {
            $i = $f['i'];
            $wd = $p->iWd[$i];
            if ($f['teacher'] !== null) {
                $t = $f['teacher'];
                $this->tF[$t][$i] = ($this->tF[$t][$i] ?? 0) + 1;
                $this->tLoad[$t] = ($this->tLoad[$t] ?? 0) + 1;
                $this->tFixedDay[$t][$wd] = ($this->tFixedDay[$t][$wd] ?? 0) + 1;
                $this->tFixedIv[$t][$wd][] = [$p->iS[$i], $p->iE[$i]];
            }
            if ($f['room'] !== null) {
                $this->rF[$f['room']][$i] = ($this->rF[$f['room']][$i] ?? 0) + 1;
            }
            if ($f['class'] !== null) {
                $this->cF[$f['class']][$i] = ($this->cF[$f['class']][$i] ?? 0) + 1;
                if ($f['subject'] !== null) {
                    $this->cFixedDay[$f['class']][$wd][] = [$f['subject'], $i];
                    $this->cFixedSubj[$f['class']][$wd][$f['subject']] = ($this->cFixedSubj[$f['class']][$wd][$f['subject']] ?? 0) + 1;
                    $this->dirtyCD[$f['class']][$wd] = true;
                }
            }
        }
        foreach ($p->tTarget as $t => $target) {
            if ($target !== null) {
                $this->dirtyTB[$t] = true; // hedef sapması baştan hesaplanmalı (yoksa hedefli öğretmen pahalı görünür)
            }
        }
        foreach ($this->tFixedDay as $t => $_) {
            if (isset($p->tMax[$t])) {
                $this->dirtyTB[$t] = true;
                foreach ($_ as $wd => $__) {
                    $this->dirtyTD[$t][$wd] = true;
                }
            }
        }
        $this->refresh();
        $this->baseTotal = $this->total;
        $this->baseRaw = $this->rawComponents();
        $this->baseRaw['target'] = 0.0; // hedef sapması yerleşimle değişir; tabandan düşülmez
    }

    // ------------------------------------------------------------------ doluluk

    public function classFree(int $c, int $i): bool
    {
        foreach ($this->p->overlap[$i] as $j) {
            if (! empty($this->cU[$c][$j]) || isset($this->cF[$c][$j])) {
                return false;
            }
        }

        return true;
    }

    public function teacherFree(int $t, int $i): bool
    {
        foreach ($this->p->overlap[$i] as $j) {
            if (! empty($this->tU[$t][$j]) || isset($this->tF[$t][$j])) {
                return false;
            }
        }

        return true;
    }

    public function roomFree(int $r, int $i): bool
    {
        foreach ($this->p->overlap[$i] as $j) {
            if (! empty($this->rU[$r][$j]) || isset($this->rF[$r][$j])) {
                return false;
            }
        }

        return true;
    }

    public function teacherHasCapacity(int $t): bool
    {
        return ($this->tLoad[$t] ?? 0) < ($this->p->tMax[$t] ?? 0);
    }

    /** Sınıfa özel günlük üst sınır (sert): grubun o günkü saati sınırın altında mı. */
    public function dayCapOk(int $g, int $i): bool
    {
        $max = $this->p->groupMaxDay[$g] ?? null;
        if ($max === null) {
            return true;
        }
        $c = $this->p->groupClass[$g];
        $sub = $this->p->groupSubject[$g];
        $wd = $this->p->iWd[$i];

        return ($this->csd[$c][$wd][$sub] ?? 0) + ($this->cFixedSubj[$c][$wd][$sub] ?? 0) < $max;
    }

    /** Tüm sert kısıtlar (ünite yerleşik DEĞİLKEN çağrılmalı). */
    public function canPlace(int $u, int $i, int $t, int $r): bool
    {
        $c = $this->p->unitClass[$u];

        return isset($this->p->classSlotSet[$c][$i]) && isset($this->p->classRoomSet[$c][$r])
            && $this->p->avail($t, $i) && $this->teacherHasCapacity($t) && $this->dayCapOk($this->p->unitGroup[$u], $i)
            && $this->classFree($c, $i) && $this->teacherFree($t, $i) && $this->roomFree($r, $i);
    }

    /** Aralıkta boş, kapasitesi yeten ilk derslik (tercih sırası: verilen, ana derslik, küçükten büyüğe). */
    public function freeRoom(int $c, int $i, ?int $prefer = null): ?int
    {
        if ($prefer !== null && isset($this->p->classRoomSet[$c][$prefer]) && $this->roomFree($prefer, $i)) {
            return $prefer;
        }
        foreach ($this->p->classRooms[$c] as $r) {
            if ($this->roomFree($r, $i)) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Ünite (i, t, r)'ye konursa çıkarılması gereken yerleşik üniteler; sabit bir engel varsa null.
     *
     * @return array<int,true>|null
     */
    public function conflictsFor(int $u, int $i, int $t, int $r): ?array
    {
        $c = $this->p->unitClass[$u];
        if (! $this->dayCapOk($this->p->unitGroup[$u], $i)) {
            return null;
        }
        $out = [];
        foreach ($this->p->overlap[$i] as $j) {
            if (isset($this->cF[$c][$j]) || isset($this->tF[$t][$j]) || isset($this->rF[$r][$j])) {
                return null;
            }
            $out += $this->cU[$c][$j] ?? [];
            $out += $this->tU[$t][$j] ?? [];
            $out += $this->rU[$r][$j] ?? [];
        }
        unset($out[$u]);

        return $out;
    }

    /** @return list<int> öğretmenin yerleşik üniteleri */
    public function teacherUnits(int $t): array
    {
        $out = [];
        foreach ($this->tdUnits[$t] ?? [] as $units) {
            foreach ($units as $u => $_) {
                $out[] = $u;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ değişiklik

    public function place(int $u, int $i, int $t, int $r): void
    {
        $p = $this->p;
        $c = $p->unitClass[$u];
        $g = $p->unitGroup[$u];
        $wd = $p->iWd[$i];

        $this->aI[$u] = $i;
        $this->aT[$u] = $t;
        $this->aR[$u] = $r;
        $this->placed++;
        $this->tU[$t][$i][$u] = true;
        $this->rU[$r][$i][$u] = true;
        $this->cU[$c][$i][$u] = true;
        $this->tLoad[$t] = ($this->tLoad[$t] ?? 0) + 1;
        $this->cdUnits[$c][$wd][$u] = true;
        $this->tdUnits[$t][$wd][$u] = true;
        $this->gT[$g][$t] = ($this->gT[$g][$t] ?? 0) + 1;
        $sub = $p->unitSubject[$u];
        $this->csd[$c][$wd][$sub] = ($this->csd[$c][$wd][$sub] ?? 0) + 1;

        $this->dirtyCD[$c][$wd] = true;
        $this->dirtyTD[$t][$wd] = true;
        $this->dirtyTB[$t] = true;
        $this->dirtyU[$u] = true;
        $this->dirtyG[$g] = true;
    }

    public function unplace(int $u): void
    {
        $i = $this->aI[$u];
        if ($i < 0) {
            return;
        }
        $p = $this->p;
        $t = $this->aT[$u];
        $r = $this->aR[$u];
        $c = $p->unitClass[$u];
        $g = $p->unitGroup[$u];
        $wd = $p->iWd[$i];

        unset($this->tU[$t][$i][$u], $this->rU[$r][$i][$u], $this->cU[$c][$i][$u], $this->cdUnits[$c][$wd][$u], $this->tdUnits[$t][$wd][$u]);
        $this->tLoad[$t]--;
        $this->csd[$c][$wd][$p->unitSubject[$u]]--;
        if (--$this->gT[$g][$t] <= 0) {
            unset($this->gT[$g][$t]);
        }
        $this->aI[$u] = $this->aT[$u] = $this->aR[$u] = -1;
        $this->placed--;

        $this->dirtyCD[$c][$wd] = true;
        $this->dirtyTD[$t][$wd] = true;
        $this->dirtyTB[$t] = true;
        $this->dirtyU[$u] = true;
        $this->dirtyG[$g] = true;
    }

    /** Kirli anahtarların cezasını yeniden hesapla; toplamı güncelle. */
    public function refresh(): float
    {
        foreach ($this->dirtyCD as $c => $days) {
            foreach ($days as $wd => $_) {
                [$over, $block] = $this->rawClassDay($c, $wd);
                $v = $this->w['spread'] * $over + $this->w['block'] * $block;
                $this->total += $v - ($this->cacheCD[$c][$wd] ?? 0.0);
                $this->cacheCD[$c][$wd] = $v;
            }
        }
        foreach ($this->dirtyTD as $t => $days) {
            foreach ($days as $wd => $_) {
                $v = $this->w['gaps'] * $this->rawTeacherDay($t, $wd);
                $this->total += $v - ($this->cacheTD[$t][$wd] ?? 0.0);
                $this->cacheTD[$t][$wd] = $v;
            }
        }
        foreach ($this->dirtyTB as $t => $_) {
            $v = $this->w['balance'] * $this->rawBalance($t) + $this->w['target'] * $this->rawTarget($t);
            $this->total += $v - ($this->cacheTB[$t] ?? 0.0);
            $this->cacheTB[$t] = $v;
        }
        foreach ($this->dirtyU as $u => $_) {
            [$home, $late] = $this->rawUnit($u);
            $v = $this->w['homeroom'] * $home + $this->w['hard_early'] * $late;
            $this->total += $v - ($this->cacheU[$u] ?? 0.0);
            $this->cacheU[$u] = $v;
        }
        foreach ($this->dirtyG as $g => $_) {
            $v = $this->w['consistency'] * $this->rawGroup($g);
            $this->total += $v - ($this->cacheG[$g] ?? 0.0);
            $this->cacheG[$g] = $v;
        }
        $this->dirtyCD = $this->dirtyTD = $this->dirtyTB = $this->dirtyU = $this->dirtyG = [];

        return $this->total;
    }

    // ------------------------------------------------------------------ ceza bileşenleri (ham)

    /** @return array{0:int,1:int} [üst sınırı aşan saat, ardışık olmayan ders] */
    public function rawClassDay(int $c, int $wd): array
    {
        $p = $this->p;
        $counts = [];
        $idx = [];
        foreach ($this->cdUnits[$c][$wd] ?? [] as $u => $_) {
            $s = $p->unitSubject[$u];
            $counts[$s] = ($counts[$s] ?? 0) + 1;
            $idx[$s][] = $p->slotIdx[$c][$this->aI[$u]];
        }
        foreach ($this->cFixedDay[$c][$wd] ?? [] as [$s, $i]) {
            $counts[$s] = ($counts[$s] ?? 0) + 1;
            if (isset($p->slotIdx[$c][$i])) {
                $idx[$s][] = $p->slotIdx[$c][$i];
            }
        }
        $over = 0;
        $block = 0;
        foreach ($counts as $s => $n) {
            $g = $p->classSubjectGroup[$c][$s] ?? null;
            $limit = $g === null ? $p->maxPerDay : $p->softMaxDay($g);
            if ($n > $limit) {
                $over += $n - $limit;
            }
            if ($n >= 2 && count($idx[$s] ?? []) === $n && max($idx[$s]) - min($idx[$s]) + 1 !== $n) {
                $block++;
            }
            if ($g !== null && $p->groupBlock[$g] === 2 && $n % 2 === 1) {
                $block++; // blok derste o gün tek saat kaldı
            }
        }

        return [$over, $block];
    }

    /** Öğretmenin o günkü boş saatleri (saat cinsinden; 20 dk'ya kadar teneffüs sayılmaz). */
    public function rawTeacherDay(int $t, int $wd): float
    {
        $p = $this->p;
        $iv = $this->tFixedIv[$t][$wd] ?? [];
        foreach ($this->tdUnits[$t][$wd] ?? [] as $u => $_) {
            $i = $this->aI[$u];
            $iv[] = [$p->iS[$i], $p->iE[$i]];
        }
        if (count($iv) < 2) {
            return 0.0;
        }
        usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
        $gap = 0.0;
        $end = $iv[0][1];
        for ($k = 1, $n = count($iv); $k < $n; $k++) {
            $idle = $iv[$k][0] - $end;
            if ($idle > 20) {
                $gap += ($idle - 10) / 60;
            }
            $end = max($end, $iv[$k][1]);
        }

        return $gap;
    }

    /** Günlük yük dağılımının eşitlikten sapması (0 = eşit). */
    public function rawBalance(int $t): float
    {
        $loads = [];
        foreach ($this->tdUnits[$t] ?? [] as $wd => $units) {
            $loads[$wd] = count($units);
        }
        foreach ($this->tFixedDay[$t] ?? [] as $wd => $n) {
            $loads[$wd] = ($loads[$wd] ?? 0) + $n;
        }
        $total = array_sum($loads);
        if ($total <= 0) {
            return 0.0;
        }
        $sq = 0;
        foreach ($loads as $l) {
            $sq += $l * $l;
        }

        return max(0.0, ($sq - $total * $total / max($this->dayCount, count($loads))) / $total);
    }

    /** Haftalık yükün hedef saatten sapması (saat). Hedef yoksa 0. */
    public function rawTarget(int $t): float
    {
        $target = $this->p->tTarget[$t] ?? null;

        return $target === null ? 0.0 : (float) abs(($this->tLoad[$t] ?? 0) - $target);
    }

    /** @return array{0:int,1:float} [ana derslik dışında, zor dersin gün içi konumu] */
    public function rawUnit(int $u): array
    {
        $i = $this->aI[$u];
        if ($i < 0) {
            return [0, 0.0];
        }
        $p = $this->p;
        $c = $p->unitClass[$u];
        $home = $p->homeroom[$c] ?? null;

        return [
            $home !== null && $this->aR[$u] !== $home ? 1 : 0,
            ! empty($p->subjects[$p->unitSubject[$u]]['hard']) ? ($p->slotPos[$c][$i] ?? 0.0) : 0.0,
        ];
    }

    public function rawGroup(int $g): int
    {
        return max(0, count($this->gT[$g] ?? []) - 1);
    }

    /** @return array<int,array<int,int>> öğretmen → gün → toplam ders (sabit dahil) */
    public function teacherDayLoads(): array
    {
        $out = [];
        foreach ($this->tdUnits as $t => $days) {
            foreach ($days as $wd => $units) {
                if ($units) {
                    $out[$t][$wd] = count($units);
                }
            }
        }
        foreach ($this->tFixedDay as $t => $days) {
            foreach ($days as $wd => $n) {
                $out[$t][$wd] = ($out[$t][$wd] ?? 0) + $n;
            }
        }

        return $out;
    }

    /**
     * Bileşen bazında ham ve ağırlıklı cezalar (tam yeniden hesap; rapor için).
     * Seçilmeyen sınıfların sabit derslerinden gelen taban ceza düşülür (bot onu değiştiremez).
     */
    public function breakdown(): array
    {
        $raw = $this->rawComponents();

        $out = [];
        $sum = 0.0;
        foreach ($raw as $k => $v) {
            $v = max(0.0, $v - ($this->baseRaw[$k] ?? 0.0));
            $weighted = $v * $this->w[$k];
            $sum += $weighted;
            $out[$k] = ['label' => Weights::LABELS[$k], 'raw' => round($v, 2), 'weight' => $this->w[$k], 'weighted' => round($weighted, 2)];
        }

        return ['total' => round($sum, 2), 'components' => $out];
    }

    /** @return array<string,float> ham bileşenler (taban dahil) */
    private function rawComponents(): array
    {
        $raw = array_fill_keys(array_keys(Weights::DEFAULTS), 0.0);
        foreach (array_keys($this->p->classes) as $c) {
            foreach ($this->p->days as $wd) {
                [$over, $block] = $this->rawClassDay($c, $wd);
                $raw['spread'] += $over;
                $raw['block'] += $block;
            }
        }
        $teachers = array_unique(array_merge(array_keys($this->tdUnits), array_keys($this->tFixedDay)));
        foreach ($teachers as $t) {
            if (! isset($this->p->tMax[$t])) {
                continue;
            }
            foreach ($this->p->days as $wd) {
                $raw['gaps'] += $this->rawTeacherDay($t, $wd);
            }
            $raw['balance'] += $this->rawBalance($t);
        }
        foreach (array_keys($this->p->tTarget) as $t) {
            $raw['target'] += $this->rawTarget($t);
        }
        foreach (array_keys($this->aI) as $u) {
            [$home, $late] = $this->rawUnit($u);
            $raw['homeroom'] += $home;
            $raw['hard_early'] += $late;
        }
        foreach (array_keys($this->p->groupClass) as $g) {
            $raw['consistency'] += $this->rawGroup($g);
        }

        return $raw;
    }
}
