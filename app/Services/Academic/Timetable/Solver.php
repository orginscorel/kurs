<?php

namespace App\Services\Academic\Timetable;

/**
 * Otomatik ders programı çözücüsü (saf PHP, veritabanı yok).
 *
 * 1) Kurulum — kısıt yayılımlı yerleştirme: her adımda "en kısıtlı" grup seçilir (MRV: kalan
 *    ünite başına uygun (dilim, öğretmen, derslik) sayısı en az olan), en düşük ceza artışı veren
 *    değer atanır. Boş alan kalırsa geri izleme = çıkarma zinciri: sabit engeli olmayan en az
 *    çakışmalı değer seçilir, çakışan üniteler kuyruğa geri döner (tabu listesiyle döngü önlenir).
 * 2) İyileştirme — tavlama benzetimi (simulated annealing): taşı / aynı sınıfta takas / grup
 *    öğretmenini değiştir hamleleri; yerleşemeyenler için periyodik "tek adımlı onarım" denemesi.
 *    Sert kısıtlar hamle başında denetlenir, yumuşak ceza artımsal (State) hesaplanır.
 * Aynı tohum + yineleme sınırı → aynı sonuç (süre sınırı yalnızca üst güvenlik).
 */
final class Solver
{
    private State $st;

    private Rng $rng;

    private int $t0;

    private float $timeLimit;

    private int $maxIterations;

    /** @var callable|null */
    private $onProgress;

    /** @var array<int, array{code:string, message:string, suggestions:list<string>}> grup → kalıcı engel */
    private array $permanent = [];

    /** @var array<int,true> yerleşemeyen (kalıcı olmayan) üniteler */
    private array $leftover = [];

    /** @var array<int, list<int>> */
    private array $classUnits = [];

    /** @var list<int> */
    private array $multiTeacherGroups = [];

    private ?array $best = null;

    private array $stats = ['iterations' => 0, 'accepted' => 0, 'ejections' => 0, 'repairs' => 0, 'construction_ms' => 0, 'search_ms' => 0, 'initial_penalty' => 0.0];

    private int $lastProgressAt = 0;

    public function __construct(private readonly Problem $p, array $options = [])
    {
        $this->timeLimit = max(0.2, (float) ($options['time_limit'] ?? 20));
        $this->maxIterations = max(0, (int) ($options['max_iterations'] ?? 0));
        $this->rng = new Rng((int) ($options['seed'] ?? 20260916));
    }

    public function solve(?callable $onProgress = null): array
    {
        $this->onProgress = $onProgress;
        $this->t0 = hrtime(true);
        $p = $this->p;
        $this->st = new State($p);

        foreach ($p->unitClass as $u => $c) {
            $this->classUnits[$c][] = $u;
        }
        foreach ($p->groupTeachers as $g => $ts) {
            if (count($ts) > 1) {
                $this->multiTeacherGroups[] = $g;
            }
        }

        $this->progress(1, sprintf('%d sınıf, %d ders grubu, %d ders saati, %d öğretmen, %d derslik yüklendi.', count($p->classes), $p->groupCount(), $p->unitCount(), count($p->teachers), count($p->rooms)), true);
        $this->precheck();
        $this->construct();
        $this->stats['construction_ms'] = $this->elapsedMs();
        $this->stats['initial_penalty'] = $this->st->breakdown()['total'];
        $this->progress(40, sprintf('Kurulum bitti: %d/%d ders saati yerleşti, başlangıç cezası %.1f.', $this->st->placed, $p->unitCount(), $this->stats['initial_penalty']), true);

        $this->search();
        if ($this->best !== null) {
            $this->restoreBest();
        }
        $this->stats['search_ms'] = $this->elapsedMs() - $this->stats['construction_ms'];

        $result = $this->result();
        $this->progress(100, sprintf('Tamamlandı: %d/%d yerleşti, ceza %.1f, sert kısıt ihlali %d.', $result['placed'], $result['required'], $result['penalty'], $result['hard_violations']), true);

        return $result;
    }

    // ================================================================== 0) ön denetim

    private function precheck(): void
    {
        $p = $this->p;
        $classDemand = [];
        foreach ($p->groupClass as $g => $c) {
            $classDemand[$c] = ($classDemand[$c] ?? 0) + count($p->groupUnits[$g]);
        }

        foreach ($p->groupClass as $g => $c) {
            $class = $p->classes[$c]['name'] ?? "#$c";
            $subject = $p->subjects[$p->groupSubject[$g]]['name'] ?? 'Ders';
            if (! $p->classSlots[$c]) {
                $this->permanent[$g] = ['code' => 'no_template', 'message' => "$class sınıfına zaman şablonu atanmamış.", 'suggestions' => ['Zaman şablonları sayfasından sınıfa şablon atayın.']];
            } elseif (! $p->groupTeachers[$g]) {
                $this->permanent[$g] = ['code' => 'no_teacher', 'message' => "$subject dersini verebilen aktif öğretmen tanımlı değil.", 'suggestions' => ["$subject ders sayfasından öğretmen bağlayın."]];
            } elseif (! $p->classRooms[$c]) {
                $size = (int) ($p->classes[$c]['size'] ?? 0);
                $this->permanent[$g] = ['code' => 'no_room', 'message' => "$class sınıfının mevcudu ($size) için yeterli kapasitede derslik yok.", 'suggestions' => ['Daha büyük bir derslik tanımlayın ya da sınıfı bölün.']];
            }
        }
        if ($this->permanent) {
            $this->progress(2, count($this->permanent).' ders grubu kalıcı engel nedeniyle yerleştirilemeyecek (öğretmen/şablon/derslik eksik).', true);
        }
        foreach ($p->groupClass as $g => $c) {
            $max = $p->groupMaxDay[$g];
            if ($max !== null && ! isset($this->permanent[$g])) {
                $days = count(array_unique(array_map(fn ($i) => $p->iWd[$i], $p->classSlots[$c])));
                if ($max * $days < count($p->groupUnits[$g])) {
                    $this->progress(3, sprintf('%s · %s: günde en fazla %d saat × %d gün = %d saat; %d saatin tamamı sığmayacak.', $p->classes[$c]['name'] ?? "#$c",
                        $p->subjects[$p->groupSubject[$g]]['name'] ?? 'Ders', $max, $days, $max * $days, count($p->groupUnits[$g])), true);
                }
            }
        }
        foreach ($classDemand as $c => $n) {
            if (count($p->classSlots[$c]) < $n && $p->classSlots[$c]) {
                $this->progress(3, sprintf('%s: şablonda %d dilim var, program %d saat istiyor — tamamı sığmayacak.', $p->classes[$c]['name'] ?? "#$c", count($p->classSlots[$c]), $n), true);
            }
        }
    }

    // ================================================================== 1) kurulum

    private function construct(): void
    {
        $p = $this->p;
        $st = $this->st;
        $queue = [];
        foreach ($p->groupUnits as $g => $units) {
            if (! isset($this->permanent[$g])) {
                $queue[$g] = $units;
            }
        }
        $tabu = [];
        $iter = 0;
        $maxEject = max(300, 25 * $p->unitCount());
        $deadline = $this->timeLimit * 0.45;
        $total = max(1, $p->unitCount());

        while (true) {
            $best = null;
            $bestRatio = INF;
            $bestTeachers = PHP_INT_MAX;
            foreach ($queue as $g => $units) {
                $rem = count($units);
                if ($rem === 0) {
                    continue;
                }
                $cap = $bestRatio === INF ? PHP_INT_MAX : (int) floor($bestRatio * $rem) + 1;
                $cnt = $this->domainCount($g, $cap);
                $ratio = $cnt / $rem;
                $nt = count($p->groupTeachers[$g]);
                if ($ratio < $bestRatio || ($ratio === $bestRatio && $nt < $bestTeachers)) {
                    [$best, $bestRatio, $bestTeachers] = [$g, $ratio, $nt];
                    if ($cnt === 0) {
                        break;
                    }
                }
            }
            if ($best === null) {
                break;
            }

            $u = array_pop($queue[$best]);
            $domain = $this->domain($best);

            if ($domain) {
                [$i, $t, $r] = $this->bestValue($u, $domain);
                $st->place($u, $i, $t, $r);
                $st->refresh();
            } elseif ($this->stats['ejections'] >= $maxEject || $this->elapsed() > $deadline) {
                $this->leftover[$u] = true;
            } else {
                $move = $this->bestEjection($u, $tabu, $iter);
                if ($move === null) {
                    $this->leftover[$u] = true;
                } else {
                    [$i, $t, $r, $conf] = $move;
                    foreach ($conf as $v => $_) {
                        $st->unplace($v);
                        $queue[$p->unitGroup[$v]][] = $v;
                    }
                    $st->place($u, $i, $t, $r);
                    $st->refresh();
                    $tabu[$p->unitGroup[$u].'|'.$i.'|'.$t] = $iter + 12;
                    $this->stats['ejections']++;
                }
            }

            if ((++$iter & 31) === 0) {
                $this->progress((int) min(39, 5 + 34 * $st->placed / $total), null);
            }
        }

        foreach ($queue as $units) {
            foreach ($units as $u) {
                $this->leftover[$u] = true;
            }
        }
    }

    /** Uygun (dilim, öğretmen) çifti sayısı; $cap'e ulaşınca durur. */
    private function domainCount(int $g, int $cap): int
    {
        $p = $this->p;
        $st = $this->st;
        $c = $p->groupClass[$g];
        $n = 0;
        foreach ($p->classSlots[$c] as $i) {
            if (! $st->classFree($c, $i) || ! $st->dayCapOk($g, $i) || $st->freeRoom($c, $i) === null) {
                continue;
            }
            foreach ($p->groupTeachers[$g] as $t) {
                if ($p->avail($t, $i) && $st->teacherHasCapacity($t) && $st->teacherFree($t, $i) && ++$n >= $cap) {
                    return $n;
                }
            }
        }

        return $n;
    }

    /** @return list<array{0:int,1:int,2:int}> */
    private function domain(int $g): array
    {
        $p = $this->p;
        $st = $this->st;
        $c = $p->groupClass[$g];
        $out = [];
        foreach ($p->classSlots[$c] as $i) {
            if (! $st->classFree($c, $i) || ! $st->dayCapOk($g, $i)) {
                continue;
            }
            $r = $st->freeRoom($c, $i);
            if ($r === null) {
                continue;
            }
            foreach ($p->groupTeachers[$g] as $t) {
                if ($p->avail($t, $i) && $st->teacherHasCapacity($t) && $st->teacherFree($t, $i)) {
                    $out[] = [$i, $t, $r];
                }
            }
        }

        return $out;
    }

    /** En düşük ceza artışı veren değer (küçük rastgele kırıcı ile). */
    private function bestValue(int $u, array $domain): array
    {
        $st = $this->st;
        $bestCost = INF;
        $best = $domain[0];
        foreach ($domain as $v) {
            $st->place($u, $v[0], $v[1], $v[2]);
            $cost = $st->refresh() + $this->rng->float() * 0.05;
            $st->unplace($u);
            $st->refresh();
            if ($cost < $bestCost) {
                [$bestCost, $best] = [$cost, $v];
            }
        }

        return $best;
    }

    /** @return array{0:int,1:int,2:int,3:array<int,true>}|null */
    private function bestEjection(int $u, array $tabu, int $iter): ?array
    {
        $p = $this->p;
        $st = $this->st;
        $g = $p->unitGroup[$u];
        $c = $p->unitClass[$u];
        $best = null;
        $bestScore = INF;

        foreach ($p->classSlots[$c] as $i) {
            foreach ($p->groupTeachers[$g] as $t) {
                if (! $p->avail($t, $i) || (($tabu["$g|$i|$t"] ?? -1) > $iter)) {
                    continue;
                }
                foreach ($p->classRooms[$c] as $r) {
                    $conf = $st->conflictsFor($u, $i, $t, $r);
                    if ($conf === null) {
                        continue;
                    }
                    if (! $st->teacherHasCapacity($t)) {
                        $freesLoad = false;
                        foreach ($conf as $v => $_) {
                            if ($st->aT[$v] === $t) {
                                $freesLoad = true;
                                break;
                            }
                        }
                        if (! $freesLoad) {
                            $own = $st->teacherUnits($t);
                            if (! $own) {
                                continue;
                            }
                            $conf[$this->rng->pick($own)] = true;
                        }
                    }
                    $score = count($conf) + $this->rng->float() * 0.9;
                    if ($score < $bestScore) {
                        [$bestScore, $best] = [$score, [$i, $t, $r, $conf]];
                    }
                }
            }
        }

        return $best;
    }

    // ================================================================== 2) iyileştirme

    private function search(): void
    {
        $st = $this->st;
        $n = $this->p->unitCount();
        if ($n === 0) {
            return;
        }
        $this->snapshot();
        $startSec = $this->elapsed();
        $window = max(0.05, $this->timeLimit - $startSec);
        $T0 = 3.0;
        $Tend = 0.02;
        $frac = 0.0;
        $T = $T0;

        for ($it = 0; $this->maxIterations === 0 || $it < $this->maxIterations; $it++) {
            if (($it & 127) === 0) {
                $now = $this->elapsed();
                if ($now >= $this->timeLimit) {
                    break;
                }
                $frac = $this->maxIterations > 0 ? $it / $this->maxIterations : min(1.0, ($now - $startSec) / $window);
                $T = $T0 * ($Tend / $T0) ** $frac;
                $this->progress((int) (40 + 59 * $frac), null);
            }
            if ($this->leftover && $it % 300 === 0) {
                $this->tryInsertLeftovers();
            }
            if ($st->placed === 0) {
                break;
            }

            $r = $this->rng->float();
            $accepted = $r < 0.5 ? $this->moveRelocate($T) : ($r < 0.85 ? $this->moveSwap($T) : $this->moveTeacher($T));
            if ($accepted) {
                $this->stats['accepted']++;
                if ($st->placed > $this->best[3] || ($st->placed === $this->best[3] && $st->total < $this->best[4] - 1e-9)) {
                    $this->snapshot();
                }
            }
            $this->stats['iterations'] = $it + 1;
        }
        if ($this->leftover) {
            $this->restoreBest();
            $this->tryInsertLeftovers();
            if ($st->placed > $this->best[3] || ($st->placed === $this->best[3] && $st->total < $this->best[4] - 1e-9)) {
                $this->snapshot();
            }
        }
        $this->progress(99, sprintf('İyileştirme: %d hamle denendi, %d kabul edildi.', $this->stats['iterations'], $this->stats['accepted']), true);
    }

    private function accept(float $delta, float $T): bool
    {
        return $delta <= 1e-9 || $this->rng->float() < exp(-$delta / $T);
    }

    private function randomPlaced(): int
    {
        $n = $this->p->unitCount();
        for ($k = 0; $k < 12; $k++) {
            $u = $this->rng->int($n);
            // Kilitli dersler ünite değildir (Problem::fixed) — hamlelere hiç girmez.
            if ($this->st->aI[$u] >= 0) {
                return $u;
            }
        }

        return -1;
    }

    private function moveRelocate(float $T): bool
    {
        $p = $this->p;
        $st = $this->st;
        $u = $this->randomPlaced();
        if ($u < 0) {
            return false;
        }
        $c = $p->unitClass[$u];
        [$i0, $t0, $r0] = [$st->aI[$u], $st->aT[$u], $st->aR[$u]];
        $i = $this->rng->pick($p->classSlots[$c]);
        $g = $p->unitGroup[$u];
        $t = count($p->groupTeachers[$g]) > 1 && $this->rng->float() < 0.2 ? $this->rng->pick($p->groupTeachers[$g]) : $t0;
        if ($i === $i0 && $t === $t0) {
            return false;
        }
        $before = $st->total;
        $st->unplace($u);
        $r = $st->freeRoom($c, $i, $this->rng->float() < 0.7 ? $r0 : null);
        if ($r === null || ! $st->canPlace($u, $i, $t, $r)) {
            $st->place($u, $i0, $t0, $r0);
            $st->refresh();

            return false;
        }
        $st->place($u, $i, $t, $r);
        if ($this->accept($st->refresh() - $before, $T)) {
            return true;
        }
        $st->unplace($u);
        $st->place($u, $i0, $t0, $r0);
        $st->refresh();

        return false;
    }

    private function moveSwap(float $T): bool
    {
        $p = $this->p;
        $st = $this->st;
        $u = $this->randomPlaced();
        if ($u < 0) {
            return false;
        }
        $c = $p->unitClass[$u];
        $v = -1;
        for ($k = 0; $k < 6; $k++) {
            $cand = $this->rng->pick($this->classUnits[$c]);
            if ($cand !== $u && $st->aI[$cand] >= 0 && $st->aI[$cand] !== $st->aI[$u] && $p->unitGroup[$cand] !== $p->unitGroup[$u]) {
                $v = $cand;
                break;
            }
        }
        if ($v < 0) {
            return false;
        }
        [$iu, $tu, $ru] = [$st->aI[$u], $st->aT[$u], $st->aR[$u]];
        [$iv, $tv, $rv] = [$st->aI[$v], $st->aT[$v], $st->aR[$v]];
        $before = $st->total;
        $st->unplace($u);
        $st->unplace($v);

        $ok = false;
        $ru2 = $st->freeRoom($c, $iv, $ru);
        if ($ru2 !== null && $st->canPlace($u, $iv, $tu, $ru2)) {
            $st->place($u, $iv, $tu, $ru2);
            $rv2 = $st->freeRoom($c, $iu, $rv);
            if ($rv2 !== null && $st->canPlace($v, $iu, $tv, $rv2)) {
                $st->place($v, $iu, $tv, $rv2);
                $ok = true;
            } else {
                $st->unplace($u);
            }
        }
        if ($ok && $this->accept($st->refresh() - $before, $T)) {
            return true;
        }
        if ($ok) {
            $st->unplace($u);
            $st->unplace($v);
        }
        $st->place($u, $iu, $tu, $ru);
        $st->place($v, $iv, $tv, $rv);
        $st->refresh();

        return false;
    }

    private function moveTeacher(float $T): bool
    {
        $p = $this->p;
        $st = $this->st;
        if (! $this->multiTeacherGroups) {
            return $this->moveRelocate($T);
        }
        $g = $this->rng->pick($this->multiTeacherGroups);
        $target = $this->rng->pick($p->groupTeachers[$g]);
        $olds = [];
        foreach ($p->groupUnits[$g] as $u) {
            if ($st->aI[$u] >= 0 && $st->aT[$u] !== $target) {
                $olds[$u] = [$st->aI[$u], $st->aT[$u], $st->aR[$u]];
            }
        }
        if (! $olds) {
            return false;
        }
        $before = $st->total;
        foreach ($olds as $u => $_) {
            $st->unplace($u);
        }
        $done = [];
        foreach ($olds as $u => [$i, $t, $r]) {
            if (! $st->canPlace($u, $i, $target, $r)) {
                break;
            }
            $st->place($u, $i, $target, $r);
            $done[] = $u;
        }
        if (count($done) === count($olds) && $this->accept($st->refresh() - $before, $T)) {
            return true;
        }
        foreach ($done as $u) {
            $st->unplace($u);
        }
        foreach ($olds as $u => [$i, $t, $r]) {
            $st->place($u, $i, $t, $r);
        }
        $st->refresh();

        return false;
    }

    /** Yerleşemeyenleri doğrudan ya da tek adımlı onarımla (bir üniteyi başka yere kaydırarak) yerleştir. */
    private function tryInsertLeftovers(): void
    {
        $p = $this->p;
        $st = $this->st;
        foreach (array_keys($this->leftover) as $u) {
            $g = $p->unitGroup[$u];
            $domain = $this->domain($g);
            if ($domain) {
                [$i, $t, $r] = $this->bestValue($u, $domain);
                $st->place($u, $i, $t, $r);
                $st->refresh();
                unset($this->leftover[$u]);

                continue;
            }
            if ($this->repair($u)) {
                unset($this->leftover[$u]);
                $this->stats['repairs']++;
            }
        }
    }

    private function repair(int $u): bool
    {
        $p = $this->p;
        $st = $this->st;
        $g = $p->unitGroup[$u];
        $c = $p->unitClass[$u];
        $tries = 0;
        foreach ($p->classSlots[$c] as $i) {
            foreach ($p->groupTeachers[$g] as $t) {
                if (! $p->avail($t, $i)) {
                    continue;
                }
                foreach ($p->classRooms[$c] as $r) {
                    $conf = $st->conflictsFor($u, $i, $t, $r);
                    if ($conf === null || count($conf) !== 1 || ++$tries > 80) {
                        if ($tries > 80) {
                            return false;
                        }

                        continue;
                    }
                    $v = array_key_first($conf);
                    [$iv, $tv, $rv] = [$st->aI[$v], $st->aT[$v], $st->aR[$v]];
                    $st->unplace($v);
                    if (! $st->canPlace($u, $i, $t, $r)) {
                        $st->place($v, $iv, $tv, $rv);

                        continue;
                    }
                    $st->place($u, $i, $t, $r);
                    $dv = array_values(array_filter($this->domain($p->unitGroup[$v]), fn ($x) => ! ($x[0] === $iv && $x[1] === $tv)));
                    if ($dv) {
                        [$i2, $t2, $r2] = $this->bestValue($v, $dv);
                        $st->place($v, $i2, $t2, $r2);
                        $st->refresh();

                        return true;
                    }
                    $st->unplace($u);
                    $st->place($v, $iv, $tv, $rv);
                    $st->refresh();
                }
            }
        }

        return false;
    }

    private function snapshot(): void
    {
        $this->best = [$this->st->aI, $this->st->aT, $this->st->aR, $this->st->placed, $this->st->total];
    }

    private function restoreBest(): void
    {
        $st = $this->st;
        foreach ($st->aI as $u => $i) {
            if ($i >= 0) {
                $st->unplace($u);
            }
        }
        [$bI, $bT, $bR] = $this->best;
        foreach ($bI as $u => $i) {
            if ($i >= 0) {
                $st->place($u, $i, $bT[$u], $bR[$u]);
            }
        }
        $st->refresh();
        $this->leftover = [];
        foreach ($st->aI as $u => $i) {
            if ($i < 0 && ! isset($this->permanent[$this->p->unitGroup[$u]])) {
                $this->leftover[$u] = true;
            }
        }
    }

    // ================================================================== sonuç

    private function result(): array
    {
        $p = $this->p;
        $st = $this->st;
        $placements = [];
        $check = [];
        foreach ($st->aI as $u => $i) {
            if ($i < 0) {
                continue;
            }
            $check[$u] = [$i, $st->aT[$u], $st->aR[$u]];
            $placements[] = [
                'class' => $p->unitClass[$u], 'subject' => $p->unitSubject[$u], 'teacher' => $st->aT[$u], 'room' => $st->aR[$u],
                'weekday' => $p->iWd[$i], 'start' => Problem::time($p->iS[$i]), 'end' => Problem::time($p->iE[$i]),
            ];
        }
        usort($placements, fn ($a, $b) => [$a['class'], $a['weekday'], $a['start']] <=> [$b['class'], $b['weekday'], $b['start']]);

        $violations = HardConstraints::violations($p, $check);
        $breakdown = $st->breakdown();
        $required = $p->unitCount();
        $placed = count($placements);
        $avg = $placed > 0 ? $breakdown['total'] / $placed : 0.0;
        $quality = $required > 0 ? (int) round(100 * ($placed / $required) * exp(-$avg / 4)) : 100;

        return [
            'placements' => $placements,
            'unplaced' => $this->diagnose(),
            'required' => $required,
            'placed' => $placed,
            'penalty' => $breakdown['total'],
            'quality' => $quality,
            'components' => $breakdown['components'],
            'hard_violations' => count($violations),
            'violations' => array_slice($violations, 0, 30),
            'teacher_loads' => $this->teacherLoads(),
            'stats' => [...$this->stats, 'total_ms' => $this->elapsedMs()],
        ];
    }

    /** @return list<array{class:int, subject:int, hours:int, code:string, message:string, suggestions:list<string>}> */
    private function diagnose(): array
    {
        $p = $this->p;
        $st = $this->st;
        $missing = [];
        foreach ($st->aI as $u => $i) {
            if ($i < 0) {
                $g = $p->unitGroup[$u];
                $missing[$g] = ($missing[$g] ?? 0) + 1;
            }
        }
        $classDemand = [];
        foreach ($p->groupClass as $g => $c) {
            $classDemand[$c] = ($classDemand[$c] ?? 0) + count($p->groupUnits[$g]);
        }

        $out = [];
        foreach ($missing as $g => $k) {
            $c = $p->groupClass[$g];
            $s = $p->groupSubject[$g];
            $class = $p->classes[$c]['name'] ?? "#$c";
            $subject = $p->subjects[$s]['name'] ?? 'Ders';
            $base = ['class' => $c, 'subject' => $s, 'hours' => $k];
            $prefix = "$class · $subject: $k saat yerleşemedi — ";

            if (isset($this->permanent[$g])) {
                $out[] = $base + ['code' => $this->permanent[$g]['code'], 'message' => $prefix.$this->permanent[$g]['message'], 'suggestions' => $this->permanent[$g]['suggestions']];

                continue;
            }
            $slots = count($p->classSlots[$c]);
            if ($slots < $classDemand[$c]) {
                $out[] = $base + ['code' => 'template_short', 'message' => $prefix."sınıfın şablonunda $slots ders saati var, program {$classDemand[$c]} saat istiyor.",
                    'suggestions' => ['Sınıfın zaman şablonuna dilim ekleyin ya da ikinci bir şablon atayın.', 'Programdaki haftalık ders saatlerini gözden geçirin.']];

                continue;
            }
            $teachers = $p->groupTeachers[$g];
            $names = implode(', ', array_map(fn ($t) => $p->teachers[$t]['name'] ?? "#$t", $teachers));
            $availSomewhere = false;
            foreach ($p->classSlots[$c] as $i) {
                foreach ($teachers as $t) {
                    if ($p->avail($t, $i)) {
                        $availSomewhere = true;
                        break 2;
                    }
                }
            }
            if (! $availSomewhere) {
                $out[] = $base + ['code' => 'teacher_unavailable', 'message' => $prefix."öğretmenlerin ($names) uygunluk saatleri sınıfın şablonuyla örtüşmüyor.",
                    'suggestions' => ['Öğretmen uygunluğu ekleyin (Etüt → Uygunluk).', 'Derse bu saatlerde uygun başka bir öğretmen bağlayın.']];

                continue;
            }
            if (array_filter($teachers, fn ($t) => $st->teacherHasCapacity($t)) === []) {
                $out[] = $base + ['code' => 'teacher_max', 'message' => $prefix."öğretmen(ler) haftalık üst sınıra ulaştı ($names).",
                    'suggestions' => ['Öğretmenin haftalık maksimum ders saatini artırın.', 'Derse ikinci bir öğretmen bağlayın.']];

                continue;
            }

            $free = 0;
            $teacherBlocked = 0;
            $roomBlocked = 0;
            $capBlocked = 0;
            $examples = [];
            foreach ($p->classSlots[$c] as $i) {
                if (! $st->classFree($c, $i)) {
                    continue;
                }
                $free++;
                if (! $st->dayCapOk($g, $i)) {
                    $capBlocked++;

                    continue;
                }
                $tOk = false;
                foreach ($teachers as $t) {
                    if ($p->avail($t, $i) && $st->teacherHasCapacity($t) && $st->teacherFree($t, $i)) {
                        $tOk = true;
                        break;
                    }
                }
                if (! $tOk) {
                    $teacherBlocked++;
                    if (count($examples) < 3) {
                        $examples[] = $p->label($i).' (öğretmen dolu/uygun değil)';
                    }
                } elseif ($st->freeRoom($c, $i) === null) {
                    $roomBlocked++;
                    if (count($examples) < 3) {
                        $examples[] = $p->label($i).' (boş derslik yok)';
                    }
                }
            }
            if ($free === 0) {
                $out[] = $base + ['code' => 'class_full', 'message' => $prefix.'sınıfın tüm dilimleri diğer derslerle dolu.',
                    'suggestions' => ['Şablona dilim ekleyin.', 'Kilitli dersleri gözden geçirin.']];

                continue;
            }
            if ($capBlocked === $free) {
                $out[] = $base + ['code' => 'day_cap', 'message' => $prefix."sınıfın boş dilimleri, bu ders için belirlenen günlük üst sınıra (günde en fazla {$p->groupMaxDay[$g]} saat) ulaşmış günlerde.",
                    'suggestions' => ['Sınıf müfredatında bu dersin günlük üst sınırını artırın.', 'Şablona başka günlere dilim ekleyin.']];

                continue;
            }
            $suggestions = [];
            if ($teacherBlocked > 0) {
                $suggestions[] = "Öğretmen uygunluğu ekleyin ya da $subject dersine ikinci öğretmen bağlayın.";
            }
            if ($roomBlocked > 0) {
                $suggestions[] = 'Derslik ekleyin ya da şablon saatlerini kaydırın (aynı saatte çok sınıf var).';
            }
            $suggestions[] = 'Şablona dilim ekleyin.';
            $out[] = $base + ['code' => $roomBlocked > $teacherBlocked ? 'room_conflict' : 'teacher_conflict',
                'message' => $prefix."sınıfın boş $free diliminde uygun eşleşme yok: $teacherBlocked dilimde öğretmen ($names) dolu ya da uygun değil, $roomBlocked dilimde boş derslik yok".($examples ? '. Örn. '.implode('; ', $examples).'.' : '.'),
                'suggestions' => $suggestions];
        }

        return $out;
    }

    private function teacherLoads(): array
    {
        $p = $this->p;
        $st = $this->st;
        $units = [];
        foreach ($st->aT as $u => $t) {
            if ($t >= 0) {
                $units[$t] = ($units[$t] ?? 0) + 1;
            }
        }
        $days = $st->teacherDayLoads();
        foreach ($p->groupTeachers as $ts) {
            foreach ($ts as $t) {
                $days[$t] ??= [];
            }
        }
        foreach ($p->tTarget as $t => $target) {
            if ($target !== null) {
                $days[$t] ??= [];
            }
        }
        $out = [];
        foreach ($days as $t => $perDay) {
            if (! isset($p->teachers[$t])) {
                continue;
            }
            $gaps = 0.0;
            foreach (array_keys($perDay) as $wd) {
                $gaps += $st->rawTeacherDay($t, $wd);
            }
            ksort($perDay);
            $total = array_sum($perDay);
            $out[] = ['teacher' => $t, 'units' => $units[$t] ?? 0, 'fixed' => $total - ($units[$t] ?? 0), 'total' => $total, 'max' => $p->tMax[$t], 'target' => $p->tTarget[$t] ?? null,
                'per_day' => $perDay, 'gap_hours' => round($gaps, 1)];
        }
        usort($out, fn ($a, $b) => [$b['total'], $a['teacher']] <=> [$a['total'], $b['teacher']]);

        return $out;
    }

    // ================================================================== yardımcılar

    private function elapsed(): float
    {
        return (hrtime(true) - $this->t0) / 1e9;
    }

    private function elapsedMs(): int
    {
        return (int) round((hrtime(true) - $this->t0) / 1e6);
    }

    private function progress(int $pct, ?string $message, bool $force = false): void
    {
        if (! $this->onProgress) {
            return;
        }
        $now = $this->elapsedMs();
        if (! $force && $now - $this->lastProgressAt < 800) {
            return;
        }
        $this->lastProgressAt = $now;
        ($this->onProgress)(max(0, min(100, $pct)), $message);
    }
}
