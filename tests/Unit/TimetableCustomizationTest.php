<?php

namespace Tests\Unit;

use App\Services\Academic\Timetable\Problem;
use App\Services\Academic\Timetable\Solver;
use PHPUnit\Framework\TestCase;

/** Sınıfa özel müfredat (sabit öğretmen, günlük sınır, blok) ve öğretmen hedef saati. */
class TimetableCustomizationTest extends TestCase
{
    private static function evening(array $days, int $count): array
    {
        $slots = [];
        foreach ($days as $wd) {
            for ($k = 0; $k < $count; $k++) {
                $s = 990 + $k * 50;
                $slots[] = [$wd, $s, $s + 40];
            }
        }

        return $slots;
    }

    private static function solve(Problem $p, int $seed = 11, int $iterations = 4000): array
    {
        return (new Solver($p, ['seed' => $seed, 'max_iterations' => $iterations, 'time_limit' => 30]))->solve();
    }

    public function test_fixed_teacher_is_used_even_without_branch_link(): void
    {
        $p = new Problem(
            classes: [1 => ['name' => '12-A', 'size' => 15, 'slots' => self::evening([1, 2], 3)]],
            subjects: [100 => ['name' => 'Matematik']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 20], 2 => ['name' => 'Kemal', 'subjects' => [], 'max_units' => 20]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 4, 'teachers' => [2], 'strict' => true]],
        );
        $r = self::solve($p);

        $this->assertSame(4, $r['placed']);
        $this->assertSame([2], array_values(array_unique(array_column($r['placements'], 'teacher'))));
        $this->assertSame(0, $r['hard_violations'], implode("\n", $r['violations']));
    }

    public function test_class_daily_cap_is_a_hard_limit(): void
    {
        // 3 gün × 4 dilim; 6 saat matematik, günde en fazla 2 → her güne 2
        $p = new Problem(
            classes: [1 => ['name' => '11-A', 'size' => 15, 'slots' => self::evening([1, 2, 3], 4)]],
            subjects: [100 => ['name' => 'Matematik'], 101 => ['name' => 'Türkçe']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 30], 2 => ['name' => 'Can', 'subjects' => [101], 'max_units' => 30]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 6, 'max_per_day' => 2], ['class' => 1, 'subject' => 101, 'hours' => 4]],
            weights: ['spread' => 0, 'block' => 0],
            maxPerDay: 6,
        );
        $r = self::solve($p);
        $this->assertSame(10, $r['placed']);
        $perDay = array_count_values(array_column(array_filter($r['placements'], fn ($x) => $x['subject'] === 100), 'weekday'));
        $this->assertSame([1 => 2, 2 => 2, 3 => 2], $perDay + [1 => 0, 2 => 0, 3 => 0]);
        $this->assertSame(0, $r['hard_violations']);

        // Sığmıyorsa: 2 gün × en fazla 1 = 2 saat; 3. saat "day_cap" nedeniyle yerleşemez
        $p2 = new Problem(
            classes: [1 => ['name' => '11-B', 'size' => 15, 'slots' => self::evening([1, 2], 3)]],
            subjects: [100 => ['name' => 'Matematik']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 30]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 3, 'max_per_day' => 1]],
        );
        $r2 = self::solve($p2);
        $this->assertSame(2, $r2['placed']);
        $this->assertSame('day_cap', $r2['unplaced'][0]['code']);
        $this->assertSame(0, $r2['hard_violations']);
    }

    public function test_block_lessons_are_paired_on_the_same_day(): void
    {
        $p = new Problem(
            classes: [1 => ['name' => '12-B', 'size' => 15, 'slots' => self::evening([1, 2, 3, 4], 3)]],
            subjects: [100 => ['name' => 'Fizik']],
            teachers: [1 => ['name' => 'Tolga', 'subjects' => [100], 'max_units' => 30]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 4, 'block' => 2]],
            weights: ['balance' => 0, 'gaps' => 0],
            maxPerDay: 1,
        );
        $r = self::solve($p);

        $this->assertSame(4, $r['placed']);
        $this->assertEquals(0.0, $r['penalty'], json_encode($r['components']));
        $byDay = [];
        foreach ($r['placements'] as $x) {
            $byDay[$x['weekday']][] = $x['start'];
        }
        foreach ($byDay as $starts) {
            $this->assertCount(2, $starts, 'Blok ders ikişer saat olmalı');
        }
    }

    public function test_teacher_target_steers_assignments_between_branch_teachers(): void
    {
        // 2 sınıf × 4 saat matematik; iki öğretmen. Ayşe'nin hedefi 8 → hepsi Ayşe'ye.
        $classes = [];
        $demands = [];
        foreach ([1, 2] as $c) {
            $classes[$c] = ['name' => "9-$c", 'size' => 15, 'slots' => self::evening([1, 2, 3, 4], 2)];
            $demands[] = ['class' => $c, 'subject' => 100, 'hours' => 4];
        }
        $make = fn (?int $targetA, ?int $targetB) => new Problem(
            classes: $classes,
            subjects: [100 => ['name' => 'Matematik']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 20, 'target' => $targetA],
                2 => ['name' => 'Recep', 'subjects' => [100], 'max_units' => 20, 'target' => $targetB]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20], 11 => ['name' => 'B', 'capacity' => 20]],
            demands: $demands,
            weights: ['gaps' => 0, 'balance' => 0],
        );

        $r = self::solve($make(8, null));
        $this->assertSame(8, $r['placed']);
        $loads = array_column($r['teacher_loads'], 'total', 'teacher');
        $this->assertSame(8, $loads[1]);
        $this->assertSame(0, $loads[2] ?? 0);
        $targets = array_column($r['teacher_loads'], 'target', 'teacher');
        $this->assertSame(8, $targets[1]);

        // Hedefler 4/4 → her öğretmene bir sınıf
        $r2 = self::solve($make(4, 4));
        $loads2 = array_column($r2['teacher_loads'], 'total', 'teacher');
        $this->assertSame([1 => 4, 2 => 4], [1 => $loads2[1], 2 => $loads2[2]]);
        $this->assertSame(0, $r2['hard_violations']);
    }

    public function test_penalty_ignores_gaps_that_come_only_from_unselected_classes(): void
    {
        // Ayşe'nin seçilmeyen sınıflarda 16:30 ve 19:00 dersi var (arada boşluk) — bot bunu değiştiremez.
        $p = new Problem(
            classes: [1 => ['name' => '10-A', 'size' => 15, 'homeroom' => 10, 'slots' => [[2, 990, 1030]]]],
            subjects: [100 => ['name' => 'Matematik']],
            teachers: [1 => ['name' => 'Ayşe', 'subjects' => [100], 'max_units' => 20]],
            rooms: [10 => ['name' => 'A', 'capacity' => 20]],
            demands: [['class' => 1, 'subject' => 100, 'hours' => 1]],
            fixed: [
                ['class' => null, 'subject' => 100, 'teacher' => 1, 'room' => null, 'weekday' => 1, 'start' => 990, 'end' => 1030],
                ['class' => null, 'subject' => 100, 'teacher' => 1, 'room' => null, 'weekday' => 1, 'start' => 1140, 'end' => 1180],
            ],
            weights: ['balance' => 0],
        );
        $r = self::solve($p);

        $this->assertSame(1, $r['placed']);
        $this->assertEquals(0.0, $r['penalty'], json_encode($r['components']));
        $this->assertSame(100, $r['quality']);
    }
}
