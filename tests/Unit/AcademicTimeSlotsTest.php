<?php

namespace Tests\Unit;

use App\Services\Academic\HomeworkService;
use App\Services\Academic\TimeSlots;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AcademicTimeSlotsTest extends TestCase
{
    public function test_time_conversion_round_trips(): void
    {
        $this->assertSame(9 * 60 + 30, TimeSlots::toMinutes('09:30'));
        $this->assertSame(16 * 60 + 30, TimeSlots::toMinutes('16:30:00'));
        $this->assertSame('09:05', TimeSlots::toTime(545));
        $this->assertSame('16:30:00', TimeSlots::normalize('16:30'));
        $this->assertSame('23:59', TimeSlots::toTime(99999));
    }

    public function test_back_to_back_lessons_do_not_overlap(): void
    {
        $this->assertFalse(TimeSlots::overlaps(540, 590, 590, 640));
        $this->assertTrue(TimeSlots::overlaps(540, 600, 590, 640));
        $this->assertTrue(TimeSlots::overlaps(500, 700, 590, 640));
    }

    public function test_subtract_returns_free_gaps_between_busy_ranges(): void
    {
        // 09:00–13:00 uygun; 10:00–10:50 ve 11:30–12:00 dolu
        $free = TimeSlots::subtract([[540, 780]], [[690, 720], [600, 650]]);
        $this->assertSame([[540, 600], [650, 690], [720, 780]], $free);
    }

    public function test_subtract_drops_gaps_shorter_than_min_length(): void
    {
        $free = TimeSlots::subtract([[540, 780]], [[600, 650], [670, 780]], 30);
        $this->assertSame([[540, 600]], $free);
    }

    public function test_subtract_handles_busy_outside_and_overlapping_window_edges(): void
    {
        $free = TimeSlots::subtract([[600, 720]], [[500, 620], [700, 800], [900, 950]]);
        $this->assertSame([[620, 700]], $free);
        $this->assertSame([], TimeSlots::subtract([[600, 720]], [[500, 800]]));
        $this->assertSame([[600, 720]], TimeSlots::subtract([[600, 720]], []));
    }

    public function test_merge_joins_touching_ranges_for_occupancy(): void
    {
        $merged = TimeSlots::merge([[600, 650], [540, 600], [700, 750], [640, 660]]);
        $this->assertSame([[540, 660], [700, 750]], $merged);
        $this->assertSame([], TimeSlots::merge([]));
    }

    public function test_occupancy_is_percentage_capped_at_100(): void
    {
        $this->assertSame(50.0, TimeSlots::occupancy(390, 780));
        $this->assertSame(100.0, TimeSlots::occupancy(1000, 780));
        $this->assertSame(0.0, TimeSlots::occupancy(10, 0));
        $this->assertSame(33.3, TimeSlots::occupancy(100, 300));
    }

    public function test_week_start_is_monday(): void
    {
        $this->assertSame('2026-09-14', TimeSlots::weekStart(CarbonImmutable::parse('2026-09-17'))->toDateString()); // Perşembe
        $this->assertSame('2026-09-14', TimeSlots::weekStart(CarbonImmutable::parse('2026-09-20'))->toDateString()); // Pazar
        $this->assertSame('2026-09-14', TimeSlots::weekStart(CarbonImmutable::parse('2026-09-14'))->toDateString()); // Pazartesi
    }

    public function test_homework_submission_status_depends_on_due_date(): void
    {
        $due = CarbonImmutable::parse('2026-09-20 23:59:00');
        $this->assertSame('submitted', HomeworkService::statusForSubmission($due, CarbonImmutable::parse('2026-09-20 22:00:00')));
        $this->assertSame('submitted', HomeworkService::statusForSubmission($due, $due));
        $this->assertSame('late', HomeworkService::statusForSubmission($due, CarbonImmutable::parse('2026-09-21 00:01:00')));
    }
}
