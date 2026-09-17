<?php

namespace Tests\Unit;

use App\Services\Automation\AttendanceMessageGuard;
use App\Services\Automation\ConditionEvaluator;
use App\Services\Automation\DedupeKey;
use App\Services\Automation\StudentActivityGate;
use App\Services\Notifications\NotificationType;
use PHPUnit\Framework\TestCase;

class AutomationWiringGuardsTest extends TestCase
{
    public function test_absent_and_late_share_attendance_prefix_in_dedupe_key(): void
    {
        $absent = DedupeKey::build(['rule', 1, 'attendance.absent', 7, AttendanceMessageGuard::suffix(55, 'attendance.absent')], 160);
        $late = DedupeKey::build(['rule', 2, 'attendance.late', 7, AttendanceMessageGuard::suffix(55, 'attendance.late')], 160);

        $this->assertStringContainsString(':att:55:', $absent);
        $this->assertStringContainsString(':att:55:', $late);
        $this->assertNotSame($absent, $late);
        $this->assertSame('attendance.absent', AttendanceMessageGuard::triggerFromDedupeKey($absent));
        $this->assertSame('attendance.late', AttendanceMessageGuard::triggerFromDedupeKey($late));
    }

    public function test_late_is_suppressed_when_absent_message_already_sent_for_same_lesson(): void
    {
        $this->assertFalse(AttendanceMessageGuard::shouldSend('attendance.late', ['attendance.absent']));
        $this->assertFalse(AttendanceMessageGuard::shouldSend('attendance.absent', ['attendance.late']));
    }

    public function test_first_message_and_same_trigger_other_rules_are_allowed(): void
    {
        $this->assertTrue(AttendanceMessageGuard::shouldSend('attendance.late', []));
        // aynı tetikleyicinin başka kuralı (ör. öğretmene de bildir) engellenmez; tekrarını dedupe anahtarı engeller
        $this->assertTrue(AttendanceMessageGuard::shouldSend('attendance.absent', ['attendance.absent']));
        $this->assertTrue(AttendanceMessageGuard::shouldSend('payment.received', ['attendance.absent']));
    }

    public function test_inactive_students_get_no_messages_except_payment_receipt(): void
    {
        $this->assertFalse(StudentActivityGate::allows('installment.overdue', 'withdrawn'));
        $this->assertFalse(StudentActivityGate::allows('attendance.absent', 'frozen'));
        $this->assertFalse(StudentActivityGate::allows('homework.missed', 'graduated'));
        $this->assertTrue(StudentActivityGate::allows('payment.received', 'withdrawn'));
        $this->assertTrue(StudentActivityGate::allows('installment.overdue', 'active'));
    }

    public function test_min_missed_count_condition(): void
    {
        $this->assertFalse(ConditionEvaluator::matches(['min_missed_count' => 2], ['missed_count' => 1]));
        $this->assertTrue(ConditionEvaluator::matches(['min_missed_count' => 2], ['missed_count' => 2]));
        $this->assertTrue(ConditionEvaluator::matches(['min_missed_count' => 2], []));
    }

    public function test_notification_type_fits_column_and_notification_center_categories(): void
    {
        foreach (['student.no_show_today', 'attendance.late', 'installment.overdue', 'exam.result_published', 'lesson.starting', 'risk.high', 'enrollment.welcome', 'unknown.x'] as $trigger) {
            $type = NotificationType::forTrigger($trigger);
            $this->assertContains($type, NotificationType::TYPES);
            $this->assertLessThanOrEqual(20, strlen($type));
        }
        $this->assertSame('attendance', NotificationType::forTrigger('student.no_show_today'));
        $this->assertSame('payment', NotificationType::forTrigger('installment.due'));
    }
}
