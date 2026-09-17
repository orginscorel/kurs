<?php

namespace App\Services\Placement\Core;

/**
 * Otomatik şube yerleştirme çekirdeği (saf PHP, veritabanı yok, deterministik).
 *
 * Kurallar (öncelik sırasıyla):
 *  1. Kapasite kesin: hiçbir şube kapasitesini aşmaz; yer yetmezse fazlası bekleme listesine düşer
 *     (önce sınıfsız olanlar, sonra en yeni kayıtlar). Yeni şube açılmaz.
 *  2. Şubeler arası öğrenci sayısı farkı ≤ 1 (sabitlenenler zorlarsa uyarı üretilir).
 *  3. Sabitlenen öğrenci yerinde kalır. "unplaced" kipinde mevcut üyeler de sabit sayılır.
 *  4. Denge maliyeti en aza indirilir: cinsiyet, akademik düzey (net ortalaması), yüksek risk,
 *     kardeşlerin aynı şubede olması (ayar) ve gereksiz taşıma (minimum değişiklik).
 *
 * Algoritma: iki başlangıç çözümü (yılan dağıtım / mevcut yerleşimi koru) üretilir, her biri
 * "en iyi takas" yerel aramasıyla iyileştirilir; maliyeti düşük olan seçilir (eşitlikte mevcudu koruyan).
 */
final class PlacementEngine
{
    public const DEFAULT_WEIGHTS = [
        'gender' => 3.0,     // şube başına beklenenden sapan her öğrenci
        'academic' => 1.0,   // (şube ort. − genel ort.) / std × şube mevcudu → "yanlış yerdeki öğrenci" eşdeğeri
        'risk' => 3.0,       // şube başına beklenenden sapan her yüksek riskli öğrenci
        'sibling' => 4.0,    // aynı şubedeki her kardeş çifti (bir takasın taşıma bedelinden büyük)
        'move' => 1.5,       // mevcut şubesinden taşınan her öğrenci
    ];

    private array $weights;

    public function __construct(array $weights = [])
    {
        $this->weights = array_merge(self::DEFAULT_WEIGHTS, $weights);
    }

    /**
     * @param  array<string,int>  $capacities  şube => kapasite (sıra korunur)
     * @param  list<Candidate>  $candidates
     * @param  array{mode?:string, siblings_apart?:bool}  $options
     * @return array{
     *   assignments: array<int,string>, waitlist: list<int>, moved: list<int>, placed_new: list<int>,
     *   targets: array<string,int>, before: array, after: array, score_before: int, score_after: int, warnings: list<string>
     * }
     */
    public function place(array $capacities, array $candidates, array $options = []): array
    {
        $mode = $options['mode'] ?? 'redistribute';
        $siblingsApart = (bool) ($options['siblings_apart'] ?? true);
        $sections = array_keys($capacities);
        $warnings = [];

        usort($candidates, fn (Candidate $a, Candidate $b) => $a->id <=> $b->id);
        /** @var array<int,Candidate> $byId */
        $byId = [];
        foreach ($candidates as $c) {
            if ($c->current !== null && ! isset($capacities[$c->current])) {
                $c = new Candidate($c->id, $c->gender, $c->score, $c->highRisk, $c->family, null, false, $c->priority);
            }
            $byId[$c->id] = $c;
        }

        // 1) Sabitler
        $fixed = [];
        $fixedCount = array_fill_keys($sections, 0);
        $fixedPool = array_filter($byId, fn (Candidate $c) => $c->current !== null && ($c->pinned || $mode === 'unplaced'));
        uasort($fixedPool, fn (Candidate $a, Candidate $b) => [$b->pinned, $a->priority, $a->id] <=> [$a->pinned, $b->priority, $b->id]);
        foreach ($fixedPool as $c) {
            if ($fixedCount[$c->current] < $capacities[$c->current]) {
                $fixed[$c->id] = $c->current;
                $fixedCount[$c->current]++;
            } else {
                $warnings[] = "{$c->current} şubesinde sabit öğrenci sayısı kapasiteyi aşıyor; fazlası yeniden dağıtıldı.";
            }
        }

        // 2) Kapasite: fazlası bekleme listesine
        $totalSeats = array_sum($capacities);
        $waitlist = [];
        $free = array_values(array_filter($byId, fn (Candidate $c) => ! isset($fixed[$c->id])));
        $overflow = count($byId) - $totalSeats;
        if ($overflow > 0) {
            $order = $free;
            // sınıfsızlar önce, sonra en düşük öncelik (en yeni kayıt), sonra büyük id
            usort($order, fn (Candidate $a, Candidate $b) => [$a->current !== null, $b->priority, $b->id] <=> [$b->current !== null, $a->priority, $a->id]);
            foreach (array_slice($order, 0, $overflow) as $c) {
                $waitlist[] = $c->id;
            }
            sort($waitlist);
            $free = array_values(array_filter($free, fn (Candidate $c) => ! in_array($c->id, $waitlist, true)));
        }

        $placedCount = count($fixed) + count($free);
        $targets = $this->targets($capacities, $placedCount, $fixedCount, $warnings);

        // 3) Genel istatistikler (maliyet için)
        $stats = $this->populationStats(array_merge(array_map(fn ($id) => $byId[$id], array_keys($fixed)), $free));

        // 4) İki başlangıç + yerel arama
        $snake = $this->localSearch($this->snakeStart($sections, $targets, $fixed, $free, $stats, false), $fixed, $byId, $sections, $stats, $siblingsApart);
        $keep = $this->localSearch($this->snakeStart($sections, $targets, $fixed, $free, $stats, true), $fixed, $byId, $sections, $stats, $siblingsApart);
        $costSnake = $this->cost($snake, $byId, $sections, $stats, $siblingsApart, true);
        $costKeep = $this->cost($keep, $byId, $sections, $stats, $siblingsApart, true);
        $assignments = $costSnake + 1e-9 < $costKeep ? $snake : $keep;
        ksort($assignments);

        $moved = [];
        $placedNew = [];
        foreach ($assignments as $id => $section) {
            if ($byId[$id]->current === null) {
                $placedNew[] = $id;
            } elseif ($byId[$id]->current !== $section) {
                $moved[] = $id;
            }
        }
        // Bekleme listesine düşen mevcut üyeler de taşınmış sayılır
        foreach ($waitlist as $id) {
            if ($byId[$id]->current !== null) {
                $moved[] = $id;
            }
        }
        sort($moved);

        $current = [];
        foreach ($byId as $c) {
            if ($c->current !== null) {
                $current[$c->id] = $c->current;
            }
        }
        $beforeStats = $this->populationStats(array_values(array_filter($byId, fn (Candidate $c) => $c->current !== null)));

        return [
            'assignments' => $assignments,
            'waitlist' => $waitlist,
            'moved' => $moved,
            'placed_new' => $placedNew,
            'targets' => $targets,
            'before' => $this->metrics($current, $byId, $sections, $siblingsApart, $capacities),
            'after' => $this->metrics($assignments, $byId, $sections, $siblingsApart, $capacities),
            'score_before' => $current === [] ? null : $this->balanceScore($this->cost($current, $byId, $sections, $beforeStats, $siblingsApart, false)),
            'score_after' => $this->balanceScore($this->cost($assignments, $byId, $sections, $stats, $siblingsApart, false)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Hedef şube doluysa karşılıklı takas önerileri: hedef şubedeki (sabit olmayan) öğrencilerden,
     * yer değiştirince dengeyi en az bozanlar.
     *
     * @param  list<Candidate>  $candidates  seviyedeki tüm mevcut üyeler (current dolu)
     * @return list<array{student_id:int, cost_before:float, cost_after:float, delta:float}>
     */
    public function suggestSwaps(array $capacities, array $candidates, int $studentId, string $target, int $limit = 3, bool $siblingsApart = true): array
    {
        $sections = array_keys($capacities);
        $byId = [];
        $assign = [];
        foreach ($candidates as $c) {
            $byId[$c->id] = $c;
            if ($c->current !== null && isset($capacities[$c->current])) {
                $assign[$c->id] = $c->current;
            }
        }
        $origin = $assign[$studentId] ?? null;
        if ($origin === null || $origin === $target || ! isset($capacities[$target])) {
            return [];
        }

        $stats = $this->populationStats(array_values(array_filter($byId, fn (Candidate $c) => isset($assign[$c->id]))));
        $base = $this->cost($assign, $byId, $sections, $stats, $siblingsApart, false);
        $out = [];
        foreach ($assign as $id => $section) {
            if ($section !== $target || $byId[$id]->pinned) {
                continue;
            }
            $trial = $assign;
            $trial[$studentId] = $target;
            $trial[$id] = $origin;
            $after = $this->cost($trial, $byId, $sections, $stats, $siblingsApart, false);
            $out[] = ['student_id' => $id, 'cost_before' => round($base, 3), 'cost_after' => round($after, 3), 'delta' => round($after - $base, 3)];
        }
        usort($out, fn ($a, $b) => [$a['cost_after'], $a['student_id']] <=> [$b['cost_after'], $b['student_id']]);

        return array_slice($out, 0, $limit);
    }

    /** Şube hedef sayıları: fark ≤ 1, kapasite ve sabitler gözetilir. */
    private function targets(array $capacities, int $n, array $fixedCount, array &$warnings): array
    {
        $t = array_fill_keys(array_keys($capacities), 0);
        for ($i = 0; $i < $n; $i++) {
            $best = null;
            foreach ($t as $s => $cnt) {
                if ($cnt < $capacities[$s] && ($best === null || $cnt < $t[$best])) {
                    $best = $s;
                }
            }
            if ($best === null) {
                break;
            }
            $t[$best]++;
        }

        // Sabitler hedefi aşıyorsa diğer şubelerden kaydır
        foreach ($fixedCount as $s => $fc) {
            while ($t[$s] < $fc) {
                $donor = null;
                foreach ($t as $d => $cnt) {
                    if ($d !== $s && $cnt > $fixedCount[$d] && ($donor === null || $cnt > $t[$donor])) {
                        $donor = $d;
                    }
                }
                if ($donor === null) {
                    break;
                }
                $t[$donor]--;
                $t[$s]++;
            }
        }
        if ($t !== [] && max($t) - min($t) > 1 && $n >= count($t)) {
            $warnings[] = 'Sabitlenen öğrenciler nedeniyle şubeler arası sayı farkı 1\'i aşıyor.';
        }

        return $t;
    }

    /** @param list<Candidate> $people */
    private function populationStats(array $people): array
    {
        $scores = array_values(array_filter(array_map(fn (Candidate $c) => $c->score, $people), fn ($s) => $s !== null));
        $mean = $scores ? array_sum($scores) / count($scores) : 0.0;
        $var = 0.0;
        foreach ($scores as $s) {
            $var += ($s - $mean) ** 2;
        }
        $std = count($scores) > 1 ? sqrt($var / count($scores)) : 0.0;

        $genders = [];
        $high = 0;
        foreach ($people as $c) {
            if (in_array($c->gender, ['female', 'male'], true)) {
                $genders[$c->gender] = ($genders[$c->gender] ?? 0) + 1;
            }
            $high += $c->highRisk ? 1 : 0;
        }

        return ['n' => count($people), 'mean' => $mean, 'std' => $std > 0.0001 ? $std : 1.0, 'genders' => $genders, 'high' => $high];
    }

    /**
     * Yılan dağıtım: kızlar, erkekler, diğerleri ayrı akış; her akış net sırasına göre dizilir,
     * şubeler A B B A A B… sırasıyla doldurulur. $keepCurrent ise önce mevcut şubesinde yer olanlar kalır.
     */
    private function snakeStart(array $sections, array $targets, array $fixed, array $free, array $stats, bool $keepCurrent): array
    {
        $assign = $fixed;
        $room = $targets;
        foreach ($fixed as $s) {
            $room[$s]--;
        }

        $rest = $free;
        if ($keepCurrent) {
            $rest = [];
            $byPriority = $free;
            usort($byPriority, fn (Candidate $a, Candidate $b) => [$a->priority, $a->id] <=> [$b->priority, $b->id]);
            foreach ($byPriority as $c) {
                if ($c->current !== null && ($room[$c->current] ?? 0) > 0) {
                    $assign[$c->id] = $c->current;
                    $room[$c->current]--;
                } else {
                    $rest[] = $c;
                }
            }
        }

        $genderRank = fn (?string $g) => match ($g) { 'female' => 0, 'male' => 1, default => 2 };
        usort($rest, function (Candidate $a, Candidate $b) use ($genderRank, $stats) {
            return [$genderRank($a->gender), -($a->score ?? $stats['mean']), $a->id] <=> [$genderRank($b->gender), -($b->score ?? $stats['mean']), $b->id];
        });

        $k = count($sections);
        $pattern = array_merge($sections, array_reverse($sections));
        $step = 0;
        foreach ($rest as $c) {
            for ($tries = 0; $tries < 2 * $k; $tries++) {
                $s = $pattern[$step % (2 * $k)];
                $step++;
                if ($room[$s] > 0) {
                    $assign[$c->id] = $s;
                    $room[$s]--;
                    continue 2;
                }
            }
        }

        return $assign;
    }

    /** En iyi takas yerel araması (sabitler hariç). Sayılar değişmez → fark ≤ 1 korunur. */
    private function localSearch(array $assign, array $fixed, array $byId, array $sections, array $stats, bool $siblingsApart): array
    {
        $ids = array_keys($assign);
        sort($ids);
        $movable = array_values(array_filter($ids, fn ($id) => ! isset($fixed[$id])));
        $cost = $this->cost($assign, $byId, $sections, $stats, $siblingsApart, true);

        for ($iter = 0; $iter < 400; $iter++) {
            $best = null;
            $bestCost = $cost;
            $n = count($movable);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $movable[$i];
                    $b = $movable[$j];
                    if ($assign[$a] === $assign[$b]) {
                        continue;
                    }
                    $trial = $assign;
                    [$trial[$a], $trial[$b]] = [$assign[$b], $assign[$a]];
                    $c = $this->cost($trial, $byId, $sections, $stats, $siblingsApart, true);
                    if ($c < $bestCost - 1e-6) {
                        $bestCost = $c;
                        $best = [$a, $b];
                    }
                }
            }
            if ($best === null) {
                break;
            }
            [$a, $b] = $best;
            [$assign[$a], $assign[$b]] = [$assign[$b], $assign[$a]];
            $cost = $bestCost;
        }

        return $assign;
    }

    /** Denge maliyeti (düşük = iyi). $withMoves false ise yalnız denge bileşenleri. */
    public function cost(array $assign, array $byId, array $sections, array $stats, bool $siblingsApart, bool $withMoves): float
    {
        $n = count($assign);
        if ($n === 0) {
            return 0.0;
        }
        $agg = [];
        foreach ($sections as $s) {
            $agg[$s] = ['size' => 0, 'female' => 0, 'male' => 0, 'score' => 0.0, 'high' => 0, 'families' => []];
        }
        $moves = 0;
        foreach ($assign as $id => $s) {
            $c = $byId[$id];
            $row = &$agg[$s];
            $row['size']++;
            if ($c->gender === 'female' || $c->gender === 'male') {
                $row[$c->gender]++;
            }
            $row['score'] += $c->score ?? $stats['mean'];
            $row['high'] += $c->highRisk ? 1 : 0;
            if ($c->family !== null) {
                $row['families'][$c->family] = ($row['families'][$c->family] ?? 0) + 1;
            }
            if ($c->current !== null && $c->current !== $s) {
                $moves++;
            }
            unset($row);
        }

        $total = 0.0;
        $genderTotals = ['female' => 0, 'male' => 0];
        $highTotal = 0;
        foreach ($agg as $row) {
            $genderTotals['female'] += $row['female'];
            $genderTotals['male'] += $row['male'];
            $highTotal += $row['high'];
        }
        $mean = $stats['mean'];
        foreach ($agg as $row) {
            if ($row['size'] === 0) {
                continue;
            }
            $share = $row['size'] / $n;
            // Sayım bileşenlerinde yuvarlamadan doğan kaçınılmaz sapma cezasızdır
            // (ör. 17 kız 2 şubeye 8,5 düşer → 9/8 kusursuz sayılır; 6 riskli → 3/3 beklenir, 4/2 cezalı)
            $countDev = function (float $actual, float $expected): float {
                $frac = $expected - floor($expected);

                return max(0.0, abs($actual - $expected) - min($frac, 1 - $frac));
            };
            foreach (['female', 'male'] as $g) {
                $total += $countDev($row[$g], $genderTotals[$g] * $share) * $this->weights['gender'];
            }
            $total += abs($row['score'] / $row['size'] - $mean) / $stats['std'] * $row['size'] * $this->weights['academic'];
            $total += $countDev($row['high'], $highTotal * $share) * $this->weights['risk'];
            if ($siblingsApart) {
                foreach ($row['families'] as $cnt) {
                    $total += ($cnt * ($cnt - 1) / 2) * $this->weights['sibling'];
                }
            }
        }
        if ($withMoves) {
            $total += $moves * $this->weights['move'];
        }

        return $total;
    }

    /** 0-100 denge puanı (100 = kusursuz). */
    public function balanceScore(float $cost): int
    {
        return (int) max(0, min(100, round(100 - $cost * 4)));
    }

    /** Şube bazında özet: sayı, cinsiyet, net ortalaması (yalnız sonucu olanlar), yüksek risk, kardeş çifti. */
    public function metrics(array $assign, array $byId, array $sections, bool $siblingsApart, array $capacities): array
    {
        $out = [];
        foreach ($sections as $s) {
            $out[$s] = ['section' => $s, 'capacity' => $capacities[$s], 'size' => 0, 'female' => 0, 'male' => 0, 'other' => 0, 'avg_score' => null, 'scored' => 0, 'high_risk' => 0, 'sibling_pairs' => 0];
        }
        $sums = array_fill_keys($sections, 0.0);
        $families = [];
        foreach ($assign as $id => $s) {
            $c = $byId[$id];
            $out[$s]['size']++;
            $key = in_array($c->gender, ['female', 'male'], true) ? $c->gender : 'other';
            $out[$s][$key]++;
            if ($c->score !== null) {
                $sums[$s] += $c->score;
                $out[$s]['scored']++;
            }
            $out[$s]['high_risk'] += $c->highRisk ? 1 : 0;
            if ($c->family !== null) {
                $families[$s][$c->family] = ($families[$s][$c->family] ?? 0) + 1;
            }
        }
        foreach ($sections as $s) {
            if ($out[$s]['scored'] > 0) {
                $out[$s]['avg_score'] = round($sums[$s] / $out[$s]['scored'], 2);
            }
            foreach ($families[$s] ?? [] as $cnt) {
                $out[$s]['sibling_pairs'] += intdiv($cnt * ($cnt - 1), 2);
            }
        }

        return array_values($out);
    }
}
