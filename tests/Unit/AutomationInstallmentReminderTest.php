<?php

namespace Tests\Unit;

use App\Services\Automation\InstallmentReminderPlanner;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AutomationInstallmentReminderTest extends TestCase
{
    public function test_rule_for_matches_seeded_offsets(): void
    {
        $this->assertSame('before_5', InstallmentReminderPlanner::ruleFor(-5));
        $this->assertSame('before_2', InstallmentReminderPlanner::ruleFor(-2));
        $this->assertSame('due', InstallmentReminderPlanner::ruleFor(0));
        $this->assertSame('after_3', InstallmentReminderPlanner::ruleFor(3));
        $this->assertSame('after_7', InstallmentReminderPlanner::ruleFor(7));
    }

    public function test_rule_for_returns_null_for_unmatched_day_counts(): void
    {
        $this->assertNull(InstallmentReminderPlanner::ruleFor(-3));
        $this->assertNull(InstallmentReminderPlanner::ruleFor(1));
        $this->assertNull(InstallmentReminderPlanner::ruleFor(10));
    }

    public function test_trigger_for_maps_rule_key_to_automation_trigger(): void
    {
        $this->assertSame('installment.upcoming', InstallmentReminderPlanner::triggerFor('before_5'));
        $this->assertSame('installment.upcoming', InstallmentReminderPlanner::triggerFor('before_2'));
        $this->assertSame('installment.due', InstallmentReminderPlanner::triggerFor('due'));
        $this->assertSame('installment.overdue', InstallmentReminderPlanner::triggerFor('after_3'));
        $this->assertSame('installment.overdue', InstallmentReminderPlanner::triggerFor('after_7'));
    }

    public function test_days_from_rule_key(): void
    {
        $this->assertSame(5, InstallmentReminderPlanner::daysFromRuleKey('before_5'));
        $this->assertSame(7, InstallmentReminderPlanner::daysFromRuleKey('after_7'));
        $this->assertSame(0, InstallmentReminderPlanner::daysFromRuleKey('due'));
    }

    // ------------------------------------------------------------ denetim 2026-09-16: işaret/yön düzeltmesi

    public function test_days_diff_is_today_minus_due(): void
    {
        $today = CarbonImmutable::parse('2026-09-17');

        $this->assertSame(-5, InstallmentReminderPlanner::daysDiff($today, CarbonImmutable::parse('2026-09-22')));
        $this->assertSame(-2, InstallmentReminderPlanner::daysDiff($today, CarbonImmutable::parse('2026-09-19')));
        $this->assertSame(0, InstallmentReminderPlanner::daysDiff($today, CarbonImmutable::parse('2026-09-17 15:30')));
        $this->assertSame(2, InstallmentReminderPlanner::daysDiff($today, CarbonImmutable::parse('2026-09-15')));
        $this->assertSame(3, InstallmentReminderPlanner::daysDiff($today->setTime(10, 0), CarbonImmutable::parse('2026-09-14')));
        $this->assertSame(7, InstallmentReminderPlanner::daysDiff($today, CarbonImmutable::parse('2026-09-10')));
    }

    public function test_overdue_installment_is_never_upcoming(): void
    {
        // Denetimde bulunan hata: 2 gün GECİKMİŞ taksit "vadeye 2 gün kala" sayılıyordu.
        $today = CarbonImmutable::parse('2026-09-17');
        $due = CarbonImmutable::parse('2026-09-15');

        $this->assertNull(InstallmentReminderPlanner::ruleFor(InstallmentReminderPlanner::daysDiff($today, $due)));
        $this->assertSame('after_3', InstallmentReminderPlanner::ruleFor(InstallmentReminderPlanner::daysDiff($today->addDay(), $due)));
        $this->assertSame('installment.overdue', InstallmentReminderPlanner::triggerFor('after_3'));
    }

    public function test_candidate_dates_cover_each_rule_and_map_back(): void
    {
        $today = CarbonImmutable::parse('2026-09-17');
        $dates = InstallmentReminderPlanner::candidateDates($today, InstallmentReminderPlanner::RULES);

        $this->assertSame(['2026-09-22', '2026-09-19', '2026-09-17', '2026-09-14', '2026-09-10'], $dates);

        $rules = array_map(fn ($d) => InstallmentReminderPlanner::ruleFor(InstallmentReminderPlanner::daysDiff($today, CarbonImmutable::parse($d))), $dates);
        $this->assertSame(['before_5', 'before_2', 'due', 'after_3', 'after_7'], $rules);
    }

    public function test_each_installment_matches_at_most_one_rule_per_day_and_each_rule_on_one_day(): void
    {
        $due = CarbonImmutable::parse('2026-10-01');
        $seen = [];
        for ($i = -15; $i <= 15; $i++) {
            $day = $due->addDays($i);
            $rule = InstallmentReminderPlanner::ruleFor(InstallmentReminderPlanner::daysDiff($day, $due));
            if ($rule !== null) {
                $this->assertArrayNotHasKey($rule, $seen, "{$rule} iki farklı günde eşleşti");
                $seen[$rule] = $day->toDateString();
            }
        }
        $this->assertSame(['before_5' => '2026-09-26', 'before_2' => '2026-09-29', 'due' => '2026-10-01', 'after_3' => '2026-10-04', 'after_7' => '2026-10-08'], $seen);
    }

    public function test_rules_from_settings_offsets(): void
    {
        $this->assertSame([-10 => 'before_10', -1 => 'before_1', 0 => 'due', 14 => 'after_14'], InstallmentReminderPlanner::rulesFromOffsets([14, 0, -1, -10, -1]));
        $this->assertSame(InstallmentReminderPlanner::RULES, InstallmentReminderPlanner::rulesFromOffsets([-5, -2, 0, 3, 7]));
        $this->assertSame(InstallmentReminderPlanner::RULES, InstallmentReminderPlanner::rulesFromOffsets(null));
        $this->assertSame(InstallmentReminderPlanner::RULES, InstallmentReminderPlanner::rulesFromOffsets([]));
        $this->assertSame([2 => 'after_2'], InstallmentReminderPlanner::rulesFromOffsets(['2', 'x', 99, 1.5]));
        $this->assertSame('before_10', InstallmentReminderPlanner::ruleFor(-10, InstallmentReminderPlanner::rulesFromOffsets([-10])));
        $this->assertNull(InstallmentReminderPlanner::ruleFor(-5, InstallmentReminderPlanner::rulesFromOffsets([-10])));
    }

    public function test_send_window_follows_reminder_hour(): void
    {
        $at = fn (string $t) => CarbonImmutable::parse("2026-09-17 {$t}");

        $this->assertFalse(InstallmentReminderPlanner::withinSendWindow($at('09:59'), '10:00'));
        $this->assertTrue(InstallmentReminderPlanner::withinSendWindow($at('10:00'), '10:00'));
        $this->assertTrue(InstallmentReminderPlanner::withinSendWindow($at('15:40'), '10:00'));
        $this->assertFalse(InstallmentReminderPlanner::withinSendWindow($at('21:00'), '10:00'));
        $this->assertTrue(InstallmentReminderPlanner::withinSendWindow($at('09:35'), '09:30'));
        $this->assertTrue(InstallmentReminderPlanner::withinSendWindow($at('22:10'), '22:00'));
        $this->assertFalse(InstallmentReminderPlanner::withinSendWindow($at('08:00'), 'bozuk'));
        $this->assertTrue(InstallmentReminderPlanner::withinSendWindow($at('10:05'), 'bozuk'));
    }
}
