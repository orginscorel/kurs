<?php

namespace Tests\Unit;

use App\Services\Academic\Timetable\HardConstraints;
use App\Services\Academic\Timetable\Problem;
use App\Services\Academic\Timetable\Solver;
use PHPUnit\Framework\TestCase;

class TimetableSolverTest extends TestCase
{
    /** 16:30'dan itibaren 40 dk ders + 10 dk teneffüs dilimleri */
    private static function evening(array $days, int $count, int $start = 990, int $len = 40, int $break = 10): array
    {
        $slots = [];
        foreach ($days as $wd) {
            for ($k = 0; $k < $count; $k++) {
                $s = $start + $k * ($len + $break);
                $slots[] = [$wd, $s, $s + $len];
            }
        }

        return $slots;
    }

    private static function solve(Problem $p, int $seed = 7, int $iterations = 3000): array
    {
        return (new Solver($p, ['seed' => $seed, 'max_iterations' => $iterations, 'time_limit' => 30]))->solve();
    }

    private static function index(array $result): array
    {
        return array_map(fn ($x) => "{$x['class']}|{$x['subject']}|{$x['teacher']}|{$x['room']}|{$x['weekday']}|{$x['start']}", $result['placements']);
    }

    public function test_small_problem_is_fully_placed_without_hard_violations(): void
    {
        $p = new Problem(
            classes: [1 => ['name' => '9-A', 'size' => 15, 'homeroom' => 10, 'slots' => self::evening([1, 2, 3], 4)],
                2 => ['name' => '9-B', 'size' => 15, 'homeroom' => 11, 'slots' => self::evening([1, 2, 3], 4)]],
            subjects: [100 => ['name' => 'Matematik', 'hard' => true], 101 => ['name' => 'Türkçe'], 102 => ['name' => 'Fizik', 'hard' => true]],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 30], 2 => ['name' => 'Mehmet', 'subjects' => [101, 102], 'max_units' => 30]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20], 11 => ['name' => 'B', 'capacity' => 20]],
            demands: [
                ['class' => 1, 'subject' => 100, 'hours' => 4], ['class' => 1, 'subject' => 101, 'hours' => 3], ['class' => 1, 'subject' => 102, 'hours' => 2],
                ['class' => 2, 'subject' => 100, 'hours' => 4], ['class' => 2, 'subject' => 101, 'hours' => 3], ['class' => 2, 'subject' => 102, 'hours' => 2],
            ],
        );
        $r = self::solve($p);

        $this->assertSame(18, $r['required']);
        $this->assertSame(18, $r['placed']);
        $this->assertSame([], $r['unplaced']);
        $this->assertSame(0, $r['hard_violations'], implode("\n", $r['violations']));
        // Aynı öğretmen iki şubede aynı anda olamaz
        $teacherSlots = array_map(fn ($x) => "{$x['teacher']}|{$x['weekday']}|{$x['start']}", $r['placements']);
        $this->assertSame(count($teacherSlots), count(array_unique($teacherSlots)));
    }

    public function test_known_small_problem_reaches_optimal_zero_penalty(): void
    {
        // 2 saatlik ders, günde en fazla 1 → farklı günlere; zor ders ilk dilime; ana derslikte.
        $p = new Problem(
            classes: [1 => ['name' => '10-A', 'size' => 15, 'homeroom' => 10, 'slots' => self::evening([1, 2], 2)]],
            subjects: [100 => ['name' => 'Matematik', 'hard' => true]],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 10]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20], 11 => ['name' => 'B', 'capacity' => 20]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 2]],
            weights: ['balance' => 0],
            maxPerDay: 1,
        );
        $r = self::solve($p);

        $this->assertSame(2, $r['placed']);
        $this->assertEquals(0.0, $r['penalty']);
        $days = array_column($r['placements'], 'weekday');
        sort($days);
        $this->assertSame([1, 2], $days);
        $this->assertSame(['16:30', '16:30'], array_column($r['placements'], 'start'));
        $this->assertSame([10, 10], array_column($r['placements'], 'room'));
    }

    public function test_locked_and_foreign_lessons_are_respected_as_fixed(): void
    {
        $slots = self::evening([1], 3); // 16:30, 17:20, 18:10
        $p = new Problem(
            classes: [1 => ['name' => '11-A', 'size' => 15, 'homeroom' => 10, 'slots' => $slots]],
            subjects: [100 => ['name' => 'Matematik'], 101 => ['name' => 'Kimya']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 10], 2 => ['name' => 'Can', 'subjects' => [101], 'max_units' => 10]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 1], ['class' => 1, 'subject' => 101, 'hours' => 1]],
            fixed: [
                // kilitli: sınıfın 16:30 dersi (kimya, Can, A)
                ['class' => 1, 'subject' => 101, 'teacher' => 2, 'room' => 10, 'weekday' => 1, 'start' => 990, 'end' => 1030],
                // seçilmeyen başka sınıf: Ayşe 17:20'de başka yerde
                ['class' => null, 'subject' => 100, 'teacher' => 1, 'room' => null, 'weekday' => 1, 'start' => 1040, 'end' => 1080],
            ],
        );
        $r = self::solve($p);

        $this->assertSame(2, $r['placed']);
        $this->assertSame(0, $r['hard_violations'], implode("\n", $r['violations']));
        foreach ($r['placements'] as $x) {
            $this->assertNotSame('16:30', $x['start'], 'Kilitli dersin dilimine yerleşim yapılmamalı');
        }
        $math = array_values(array_filter($r['placements'], fn ($x) => $x['subject'] === 100))[0];
        $this->assertSame('18:10', $math['start'], 'Öğretmenin başka sınıftaki dersi sabit kısıt');
    }

    public function test_unplaced_lessons_are_reported_with_reason(): void
    {
        $p = new Problem(
            classes: [1 => ['name' => '12-A', 'size' => 15, 'homeroom' => null, 'slots' => self::evening([1], 3)],
                2 => ['name' => '12-B', 'size' => 15, 'homeroom' => null, 'slots' => []]],
            subjects: [100 => ['name' => 'Matematik'], 101 => ['name' => 'Felsefe'], 102 => ['name' => 'Fizik']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 10],
                2 => ['name' => 'Bora', 'subjects' => [102], 'max_units' => 10, 'availability' => [3 => [[600, 900]]]]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20]],
            demands: [
                ['class' => 1, 'subject' => 100, 'hours' => 1],
                ['class' => 1, 'subject' => 101, 'hours' => 1],   // öğretmen yok
                ['class' => 1, 'subject' => 102, 'hours' => 1],   // öğretmen yalnız Çarşamba sabah
                ['class' => 2, 'subject' => 100, 'hours' => 1],   // şablon yok
            ],
        );
        $r = self::solve($p);

        $this->assertSame(1, $r['placed']);
        $codes = [];
        foreach ($r['unplaced'] as $u) {
            $codes["{$u['class']}|{$u['subject']}"] = $u['code'];
            $this->assertNotEmpty($u['suggestions']);
            $this->assertStringContainsString('yerleşemedi', $u['message']);
        }
        $this->assertSame('no_teacher', $codes['1|101']);
        $this->assertSame('teacher_unavailable', $codes['1|102']);
        $this->assertSame('no_template', $codes['2|100']);
        $this->assertSame(0, $r['hard_violations']);
    }

    public function test_template_shortage_is_reported(): void
    {
        $p = new Problem(
            classes: [1 => ['name' => '9-A', 'size' => 15, 'slots' => self::evening([1], 2)]],
            subjects: [100 => ['name' => 'Matematik']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 10]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 3]],
        );
        $r = self::solve($p);

        $this->assertSame(2, $r['placed']);
        $this->assertSame('template_short', $r['unplaced'][0]['code']);
        $this->assertSame(1, $r['unplaced'][0]['hours']);
    }

    public function test_capacity_and_teacher_max_are_hard_constraints(): void
    {
        $p = new Problem(
            classes: [1 => ['name' => 'Kalabalık', 'size' => 30, 'homeroom' => 10, 'slots' => self::evening([1, 2], 3)]],
            subjects: [100 => ['name' => 'Matematik']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 3], 2 => ['name' => 'Deniz', 'subjects' => [100], 'max_units' => 1]],
            rooms: [10 => ['name' => 'Küçük', 'capacity' => 20], 11 => ['name' => 'Salon', 'capacity' => 40]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 5]],
        );
        $r = self::solve($p);

        $this->assertSame(4, $r['placed'], 'Toplam öğretmen kapasitesi 3 + 1');
        $this->assertSame([11], array_values(array_unique(array_column($r['placements'], 'room'))));
        $this->assertSame('teacher_max', $r['unplaced'][0]['code']);
        $this->assertSame(0, $r['hard_violations']);
    }

    public function test_same_seed_gives_identical_result(): void
    {
        $make = fn () => $this->levelProblem();
        $a = self::solve($make(), 42, 4000);
        $b = self::solve($make(), 42, 4000);

        $this->assertSame(self::index($a), self::index($b));
        $this->assertSame($a['penalty'], $b['penalty']);
    }

    public function test_realistic_level_problem_has_no_hard_violations_and_improves_penalty(): void
    {
        $r = self::solve($this->levelProblem(), 3, 20000);

        $this->assertSame($r['required'], $r['placed'], json_encode($r['unplaced'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $r['hard_violations'], implode("\n", $r['violations']));
        $this->assertLessThanOrEqual($r['stats']['initial_penalty'], $r['penalty']);
        // Günlük üst sınır (2) iyi çözümde aşılmamalı
        $this->assertEquals(0.0, $r['components']['spread']['raw']);
        // Doğrulayıcı bağımsız: çözücü sonucunu yeniden yorumla
        $this->assertSame([], HardConstraints::violations($this->levelProblem(), []));
    }

    /** 8 şube (9–12 × A/B), 15'er öğrenci, seviye başına ortak şablon, ortak öğretmen havuzu. */
    private function levelProblem(): Problem
    {
        $subjects = [1 => ['name' => 'Matematik', 'hard' => true], 2 => ['name' => 'Türkçe'], 3 => ['name' => 'Fizik', 'hard' => true],
            4 => ['name' => 'Kimya', 'hard' => true], 5 => ['name' => 'Biyoloji'], 6 => ['name' => 'Tarih'], 7 => ['name' => 'Coğrafya'], 8 => ['name' => 'İngilizce']];
        $teachers = [
            1 => ['name' => 'Mat1', 'subjects' => [1], 'max_units' => 30], 2 => ['name' => 'Mat2', 'subjects' => [1], 'max_units' => 30],
            3 => ['name' => 'Tur1', 'subjects' => [2], 'max_units' => 30], 4 => ['name' => 'Tur2', 'subjects' => [2], 'max_units' => 30],
            5 => ['name' => 'Fiz', 'subjects' => [3], 'max_units' => 30], 6 => ['name' => 'Kim', 'subjects' => [4], 'max_units' => 30],
            7 => ['name' => 'Biy', 'subjects' => [5], 'max_units' => 30], 8 => ['name' => 'Tar', 'subjects' => [6, 7], 'max_units' => 30],
            9 => ['name' => 'Ing', 'subjects' => [8], 'max_units' => 30, 'availability' => [1 => [[960, 1260]], 2 => [[960, 1260]], 3 => [[960, 1260]], 4 => [[960, 1260]], 6 => [[540, 960]]]],
        ];
        $rooms = [];
        foreach (range(1, 8) as $k) {
            $rooms[100 + $k] = ['name' => "D$k", 'capacity' => $k === 8 ? 12 : 20];
        }
        $weekday = self::evening([1, 2, 3, 4, 5], 4);          // 20 dilim
        $saturday = self::evening([6], 6, 540, 40, 10);       // 6 dilim
        $plan = [1 => 5, 2 => 4, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 1, 8 => 2]; // 20 saat
        $classes = [];
        $demands = [];
        $id = 0;
        foreach ([9, 10, 11, 12] as $level) {
            foreach (['A', 'B'] as $k => $branch) {
                $id++;
                $classes[$id] = ['name' => "$level-$branch", 'size' => 15, 'homeroom' => 100 + $id, 'slots' => $level >= 11 ? array_merge($weekday, $saturday) : $weekday];
                foreach ($plan as $s => $h) {
                    $demands[] = ['class' => $id, 'subject' => $s, 'hours' => $h];
                }
            }
        }

        return new Problem($classes, $subjects, $teachers, $rooms, $demands);
    }
}
