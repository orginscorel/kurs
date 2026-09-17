<?php

namespace Tests\Unit;

use App\Services\Placement\Core\PromotionPlanner;
use PHPUnit\Framework\TestCase;

class PlacementPromotionTest extends TestCase
{
    public function test_grades_move_up_sections_are_kept_and_twelfth_graders_graduate(): void
    {
        $plan = (new PromotionPlanner)->plan([
            ['id' => 1, 'grade' => 9, 'section' => 'A', 'status' => 'active'],
            ['id' => 2, 'grade' => 10, 'section' => 'B', 'status' => 'active'],
            ['id' => 3, 'grade' => 11, 'section' => null, 'status' => 'active'],
            ['id' => 4, 'grade' => 12, 'section' => 'A', 'status' => 'active'],
            ['id' => 5, 'grade' => 12, 'section' => 'B', 'status' => 'frozen'],
            ['id' => 6, 'grade' => null, 'section' => null, 'status' => 'active'],
        ], [9, 10, 11, 12]);

        $this->assertSame([
            ['id' => 1, 'from' => 9, 'to' => 10, 'section' => 'A'],
            ['id' => 2, 'from' => 10, 'to' => 11, 'section' => 'B'],
            ['id' => 3, 'from' => 11, 'to' => 12, 'section' => null],
        ], $plan['promote']);
        $this->assertSame([4], $plan['graduate']);
        $this->assertSame([5, 6], array_column($plan['skipped'], 'id'));
        $this->assertSame(['10-A' => 1, '11-B' => 1], $plan['targets']);
    }

    public function test_full_target_class_leaves_student_unplaced_instead_of_exceeding_capacity(): void
    {
        $students = [];
        for ($i = 1; $i <= 3; $i++) {
            $students[] = ['id' => $i, 'grade' => 9, 'section' => 'A', 'status' => 'active'];
        }
        $plan = (new PromotionPlanner)->plan($students, [9, 10, 11, 12], ['10-A' => 15], ['10-A' => 13]);

        $this->assertSame(15, $plan['targets']['10-A']);
        $this->assertSame([['id' => 3, 'class' => '10-A']], $plan['overflow']);
        $this->assertNull($plan['promote'][2]['section']);
        $this->assertSame(10, $plan['promote'][2]['to']);
    }
}
