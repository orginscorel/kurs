<?php

namespace Tests\Unit;

use App\Services\Placement\Core\Candidate;
use App\Services\Placement\Core\PlacementEngine;
use PHPUnit\Framework\TestCase;

class PlacementEngineTest extends TestCase
{
    private const CAPS = ['A' => 15, 'B' => 15];

    /** Deterministik sahte öğrenci üretimi (rastgele değil). */
    private function people(int $n, int $startId = 1, ?callable $tweak = null): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $id = $startId + $i;
            $args = [
                'id' => $id,
                'gender' => $i % 2 === 0 ? 'female' : 'male',
                'score' => 20.0 + (($i * 7) % 60),
                'highRisk' => $i % 5 === 0,
                'family' => null,
                'current' => null,
                'pinned' => false,
                'priority' => $id,
            ];
            if ($tweak) {
                $args = $tweak($args, $i);
            }
            $out[] = new Candidate(...$args);
        }

        return $out;
    }

    private function counts(array $assignments): array
    {
        $c = ['A' => 0, 'B' => 0];
        foreach ($assignments as $s) {
            $c[$s]++;
        }

        return $c;
    }

    public function test_capacity_is_never_exceeded_and_overflow_goes_to_waitlist(): void
    {
        $r = (new PlacementEngine)->place(self::CAPS, $this->people(34));

        $c = $this->counts($r['assignments']);
        $this->assertSame(15, $c['A']);
        $this->assertSame(15, $c['B']);
        $this->assertCount(4, $r['waitlist']);
        $this->assertSame([], array_intersect($r['waitlist'], array_keys($r['assignments'])));
    }

    public function test_waitlist_prefers_unplaced_then_newest_registrations(): void
    {
        // 30 mevcut üye (15/15) + 3 sınıfsız yeni → sınıfsızlar bekleme listesine düşer
        $members = $this->people(30, 1, function ($a, $i) {
            $a['current'] = $i < 15 ? 'A' : 'B';

            return $a;
        });
        $newcomers = $this->people(3, 100);
        $r = (new PlacementEngine)->place(self::CAPS, array_merge($members, $newcomers));
        $this->assertSame([100, 101, 102], $r['waitlist']);

        // Hepsi sınıfsızsa en yeni kayıt (büyük priority) bekler
        $r2 = (new PlacementEngine)->place(self::CAPS, $this->people(32));
        $this->assertSame([31, 32], $r2['waitlist']);
    }

    public function test_section_sizes_differ_by_at_most_one(): void
    {
        foreach ([1, 7, 13, 21, 29] as $n) {
            $c = $this->counts((new PlacementEngine)->place(self::CAPS, $this->people($n))['assignments']);
            $this->assertLessThanOrEqual(1, abs($c['A'] - $c['B']), "n={$n}");
            $this->assertSame($n, $c['A'] + $c['B']);
        }
    }

    public function test_gender_is_balanced(): void
    {
        // 16 kız + 12 erkek; başlangıçta tüm kızlar A'da olacak şekilde karışık mevcut durum
        $people = $this->people(28, 1, function ($a, $i) {
            $a['gender'] = $i < 16 ? 'female' : 'male';
            $a['highRisk'] = false;

            return $a;
        });
        $r = (new PlacementEngine)->place(self::CAPS, $people);
        $a = $r['after'][0];
        $b = $r['after'][1];
        $this->assertLessThanOrEqual(1, abs($a['female'] - $b['female']));
        $this->assertLessThanOrEqual(1, abs($a['male'] - $b['male']));
    }

    public function test_academic_average_is_balanced(): void
    {
        // Güçlüler A'da, zayıflar B'de başlıyor → yeniden dağıtımda ortalamalar yakınlaşmalı
        $people = $this->people(30, 1, function ($a, $i) {
            $a['gender'] = 'female';
            $a['highRisk'] = false;
            $a['score'] = $i < 15 ? 80.0 - $i : 30.0 - ($i - 15);
            $a['current'] = $i < 15 ? 'A' : 'B';

            return $a;
        });
        $r = (new PlacementEngine)->place(self::CAPS, $people, ['mode' => 'redistribute']);
        $gapBefore = abs($r['before'][0]['avg_score'] - $r['before'][1]['avg_score']);
        $gapAfter = abs($r['after'][0]['avg_score'] - $r['after'][1]['avg_score']);
        $this->assertGreaterThan(40, $gapBefore);
        $this->assertLessThan(3, $gapAfter);
        $this->assertGreaterThan($r['score_before'], $r['score_after']);
    }

    public function test_students_without_exams_are_neutral(): void
    {
        $people = $this->people(10, 1, function ($a, $i) {
            $a['score'] = $i < 4 ? null : 50.0;

            return $a;
        });
        $r = (new PlacementEngine)->place(self::CAPS, $people);
        $this->assertCount(10, $r['assignments']);
        $this->assertEquals(50.0, $r['after'][0]['avg_score']);
    }

    public function test_high_risk_students_are_split_between_sections(): void
    {
        $people = $this->people(20, 1, function ($a, $i) {
            $a['highRisk'] = $i < 6;
            $a['gender'] = 'male';
            $a['score'] = 40.0;
            $a['current'] = $i < 10 ? 'A' : 'B';  // riskli 6 öğrencinin hepsi A'da

            return $a;
        });
        $r = (new PlacementEngine)->place(self::CAPS, $people);
        $this->assertSame(6, $r['before'][0]['high_risk']);
        $this->assertSame(3, $r['after'][0]['high_risk']);
        $this->assertSame(3, $r['after'][1]['high_risk']);
    }

    public function test_siblings_are_separated_when_enabled(): void
    {
        $people = $this->people(10, 1, function ($a, $i) {
            $a['family'] = $i < 2 ? 77 : null;
            $a['current'] = $i < 5 ? 'A' : 'B';
            $a['gender'] = 'female';
            $a['highRisk'] = false;
            $a['score'] = 50.0;

            return $a;
        });
        $on = (new PlacementEngine)->place(self::CAPS, $people, ['siblings_apart' => true]);
        $this->assertNotSame($on['assignments'][1], $on['assignments'][2]);

        $off = (new PlacementEngine)->place(self::CAPS, $people, ['siblings_apart' => false]);
        $this->assertSame([], $off['moved'], 'Ayar kapalıyken dengeli durumda kimse taşınmaz');
    }

    public function test_pinned_students_stay_in_place(): void
    {
        $people = $this->people(30, 1, function ($a, $i) {
            $a['current'] = $i < 15 ? 'A' : 'B';
            $a['score'] = $i < 15 ? 90.0 : 10.0;  // çok dengesiz → çok taşıma olur
            $a['pinned'] = in_array($i, [0, 1, 2, 15, 16], true);

            return $a;
        });
        $r = (new PlacementEngine)->place(self::CAPS, $people);
        foreach ([1 => 'A', 2 => 'A', 3 => 'A', 16 => 'B', 17 => 'B'] as $id => $section) {
            $this->assertSame($section, $r['assignments'][$id]);
        }
        $this->assertNotEmpty($r['moved']);
        $this->assertSame([], array_intersect([1, 2, 3, 16, 17], $r['moved']));
    }

    public function test_unplaced_mode_keeps_all_existing_members(): void
    {
        $members = $this->people(20, 1, function ($a, $i) {
            $a['current'] = $i < 12 ? 'A' : 'B';

            return $a;
        });
        $r = (new PlacementEngine)->place(self::CAPS, array_merge($members, $this->people(6, 50)), ['mode' => 'unplaced']);
        $this->assertSame([], $r['moved']);
        $c = $this->counts($r['assignments']);
        $this->assertSame(13, $c['A']);   // 12 + 1
        $this->assertSame(13, $c['B']);   // 8 + 5
        $this->assertCount(6, $r['placed_new']);
    }

    public function test_minimum_change_when_already_balanced(): void
    {
        $people = $this->people(30, 1, function ($a, $i) {
            $a['current'] = $i % 2 === 0 ? 'A' : 'B';
            $a['gender'] = in_array($i % 4, [0, 1], true) ? 'female' : 'male';
            $a['score'] = 50.0;
            $a['highRisk'] = false;

            return $a;
        });
        $r = (new PlacementEngine)->place(self::CAPS, $people);
        $this->assertSame([], $r['moved']);
    }

    public function test_result_is_deterministic(): void
    {
        $people = $this->people(29, 1, function ($a, $i) {
            $a['current'] = $i % 3 === 0 ? 'A' : ($i % 3 === 1 ? 'B' : null);

            return $a;
        });
        $engine = new PlacementEngine;
        $this->assertSame($engine->place(self::CAPS, $people), $engine->place(self::CAPS, array_reverse($people)));
    }

    public function test_swap_suggestions_come_from_target_section_and_skip_pinned(): void
    {
        $people = $this->people(30, 1, function ($a, $i) {
            $a['current'] = $i < 15 ? 'A' : 'B';
            $a['pinned'] = $i === 15;

            return $a;
        });
        $engine = new PlacementEngine;
        $s = $engine->suggestSwaps(self::CAPS, $people, 1, 'B', 3);
        $this->assertCount(3, $s);
        foreach ($s as $row) {
            $this->assertGreaterThanOrEqual(17, $row['student_id']);   // 16 (i=15) sabit, önerilmez
            $this->assertLessThanOrEqual(30, $row['student_id']);
        }
        $this->assertLessThanOrEqual($s[1]['cost_after'], $s[0]['cost_after']);
        // Sınıfsız öğrenci için takas önerilmez
        $this->assertSame([], $engine->suggestSwaps(self::CAPS, $this->people(3), 1, 'B'));
    }
}
