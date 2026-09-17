<?php

namespace Tests\Unit;

use App\Support\Guidance\GoalProgress;
use PHPUnit\Framework\TestCase;

class GuidanceGoalProgressTest extends TestCase
{
    public function test_average_takes_the_most_recent_n_values(): void
    {
        $this->assertSame(80.0, GoalProgress::average([90.0, 80.0, 70.0, 10.0], 3));
        $this->assertNull(GoalProgress::average([], 3));
        $this->assertSame(45.5, GoalProgress::average([45.5], 3));
    }

    public function test_percentage_is_null_without_target_or_actual(): void
    {
        $this->assertNull(GoalProgress::percentage(null, 50));
        $this->assertNull(GoalProgress::percentage(0, 50));
        $this->assertNull(GoalProgress::percentage(50, null));
    }

    public function test_percentage_is_capped_and_rounded(): void
    {
        $this->assertSame(50.0, GoalProgress::percentage(100, 50));
        $this->assertSame(120.0, GoalProgress::percentage(50, 60));
        $this->assertSame(0.0, GoalProgress::percentage(50, -10));
    }

    public function test_compare_returns_full_breakdown(): void
    {
        $result = GoalProgress::compare(90.0, 72.0);
        $this->assertSame(90.0, $result['target']);
        $this->assertSame(72.0, $result['actual']);
        $this->assertSame(80.0, $result['pct']);
        $this->assertSame(-18.0, $result['diff']);
    }

    public function test_compare_without_actual_data(): void
    {
        $result = GoalProgress::compare(90.0, null);
        $this->assertNull($result['pct']);
        $this->assertNull($result['diff']);
    }
}
