<?php

namespace Tests\Unit;

use App\Services\Academic\HolidayCalendar;
use App\Services\Academic\ICalBuilder;
use App\Services\Academic\SubstituteScorer;
use App\Services\Academic\TimeTemplateService;
use App\Exceptions\BusinessRuleException;
use PHPUnit\Framework\TestCase;

class TimetableToolsTest extends TestCase
{
    private const BASE = ['competent' => true, 'day_load' => 0, 'week_load' => 0, 'max_week' => 30, 'adjacent' => false, 'knows_class' => false];

    public function test_substitute_scoring_prefers_competent_low_load_teachers(): void
    {
        $fresh = SubstituteScorer::score(self::BASE);
        $busyDay = SubstituteScorer::score(['day_load' => 4] + self::BASE);
        $offBranch = SubstituteScorer::score(['competent' => false] + self::BASE);
        $knows = SubstituteScorer::score(['knows_class' => true, 'adjacent' => true] + self::BASE);
        $nearlyFull = SubstituteScorer::score(['week_load' => 28] + self::BASE);

        $this->assertGreaterThan($busyDay['score'], $fresh['score']);
        $this->assertGreaterThan($offBranch['score'], $busyDay['score']);
        $this->assertGreaterThan($fresh['score'], $knows['score']);
        $this->assertLessThan($fresh['score'], $nearlyFull['score']);
        $this->assertContains('Branş dışı', $offBranch['reasons']);
        $this->assertContains('O gün başka dersi yok', $fresh['reasons']);
        $this->assertStringContainsString('Haftalık yükü dolmak üzere', implode(' ', $nearlyFull['reasons']));
        foreach ([$fresh, $busyDay, $offBranch, $knows, $nearlyFull] as $s) {
            $this->assertGreaterThanOrEqual(0, $s['score']);
            $this->assertLessThanOrEqual(100, $s['score']);
        }
    }

    public function test_substitute_sort_puts_competent_first_even_with_lower_score(): void
    {
        $sorted = SubstituteScorer::sort([
            ['id' => 1, 'competent' => false, 'score' => 90],
            ['id' => 2, 'competent' => true, 'score' => 40],
            ['id' => 3, 'competent' => true, 'score' => 70],
        ]);
        $this->assertSame([3, 2, 1], array_column($sorted, 'id'));
    }

    public function test_holiday_overlap_and_lookup(): void
    {
        $this->assertTrue(HolidayCalendar::overlaps('2026-10-28', '2026-10-29', '2026-10-29', '2026-11-02'));
        $this->assertTrue(HolidayCalendar::overlaps('2026-10-29', '2026-10-29', '2026-10-01', '2026-10-31'));
        $this->assertFalse(HolidayCalendar::overlaps('2026-10-28', '2026-10-28', '2026-10-29', '2026-10-30'));

        $cal = new HolidayCalendar([
            ['id' => 1, 'name' => 'Cumhuriyet Bayramı', 'starts_on' => '2026-10-28', 'ends_on' => '2026-10-29', 'cancel_sessions' => true],
            ['id' => 2, 'name' => 'Öğretmenler Günü etkinliği', 'starts_on' => '2026-11-24', 'ends_on' => '2026-11-24', 'cancel_sessions' => false],
        ]);
        $this->assertSame(1, $cal->find('2026-10-29')['id']);
        $this->assertNull($cal->find('2026-10-30'));
        $this->assertNull($cal->find('2026-11-24'), 'Ders iptal etmeyen tatil oturumu etkilemez');
        $this->assertCount(2, $cal->between('2026-10-01', '2026-11-30'));
        $this->assertSame('Tatil: Cumhuriyet Bayramı', HolidayCalendar::reason(['name' => 'Cumhuriyet Bayramı']));
    }

    public function test_template_generator_builds_periods_with_breaks_and_lunch(): void
    {
        $days = TimeTemplateService::generate(['weekdays' => [6, 1], 'start' => '09:00', 'lesson_minutes' => 40, 'break_minutes' => 10, 'count' => 5, 'lunch_after' => 3, 'lunch_minutes' => 50]);

        $this->assertSame([1, 6], array_keys($days)); // PHP sayısal dize anahtarları tamsayıya çevirir; JSON'da "1","6"
        $this->assertSame([['09:00', '09:40'], ['09:50', '10:30'], ['10:40', '11:20'], ['12:10', '12:50'], ['13:00', '13:40']], $days[1]);
    }

    public function test_template_days_are_normalized_and_overlaps_rejected(): void
    {
        $days = TimeTemplateService::normalizeDays(['2' => [['17:30', '18:10'], ['16:30', '17:10']]]);
        $this->assertSame(['2' => [['16:30', '17:10'], ['17:30', '18:10']]], $days);

        $this->expectException(BusinessRuleException::class);
        TimeTemplateService::normalizeDays(['1' => [['16:30', '17:20'], ['17:00', '17:40']]]);
    }

    public function test_ical_output_is_valid_and_escaped(): void
    {
        $ics = ICalBuilder::build('Ders programı · 12-A', [
            ['uid' => 'lesson-1', 'date' => '2026-09-21', 'start' => '16:30', 'end' => '17:10', 'title' => 'Matematik, Geometri; tekrar', 'location' => 'Derslik A', 'description' => "Öğretmen: Ayşe\nKonu: Türev"],
            ['uid' => 'lesson-2', 'date' => '2026-09-22', 'start' => '16:30', 'end' => '17:10', 'title' => 'Fizik', 'cancelled' => true],
            ['uid' => 'holiday-1', 'date' => '2026-10-28', 'end_date' => '2026-10-29', 'title' => 'Cumhuriyet Bayramı'],
        ], 'example.test');

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('DTSTART;TZID=Europe/Istanbul:20260921T163000', $ics);
        $this->assertStringContainsString('SUMMARY:Matematik\, Geometri\; tekrar', $ics);
        $this->assertStringContainsString('DESCRIPTION:Öğretmen: Ayşe\nKonu: Türev', $ics);
        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261030', $ics, 'Tüm gün bitişi hariç tutulur');
        $this->assertStringContainsString('UID:holiday-1@example.test', $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }
}
