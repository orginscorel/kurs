<?php

namespace Tests\Unit;

use App\Support\Attendance\AttendanceRate;
use App\Support\Attendance\NoShowRule;
use App\Support\Attendance\QrSigner;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AttendanceCalcTest extends TestCase
{
    // ------------------------------------------------------------ QrSigner

    public function test_signed_qr_round_trips_and_carries_student_id(): void
    {
        $secret = 'test-secret-key';
        $value = QrSigner::generate(42, $secret);

        $this->assertTrue(QrSigner::verify($value, $secret));
        $this->assertSame(42, QrSigner::studentId($value));
    }

    public function test_qr_signature_rejects_tampering(): void
    {
        $secret = 'test-secret-key';
        $value = QrSigner::generate(7, $secret);
        $tampered = substr($value, 0, -1).($value[-1] === 'a' ? 'b' : 'a');

        $this->assertFalse(QrSigner::verify($tampered, $secret));
    }

    public function test_qr_signature_rejects_wrong_secret(): void
    {
        $value = QrSigner::generate(7, 'secret-a');
        $this->assertFalse(QrSigner::verify($value, 'secret-b'));
    }

    public function test_qr_rejects_malformed_value(): void
    {
        $this->assertFalse(QrSigner::verify('not-a-qr-code', 'any'));
        $this->assertNull(QrSigner::studentId('not-a-qr-code'));
    }

    // ------------------------------------------------------------ NoShowRule

    public function test_no_show_flags_after_delay_elapsed(): void
    {
        $start = CarbonImmutable::parse('2026-09-15 09:00:00');

        $this->assertFalse(NoShowRule::shouldFlag($start, 15, CarbonImmutable::parse('2026-09-15 09:14:00')));
        $this->assertTrue(NoShowRule::shouldFlag($start, 15, CarbonImmutable::parse('2026-09-15 09:15:00')));
        $this->assertTrue(NoShowRule::shouldFlag($start, 15, CarbonImmutable::parse('2026-09-15 10:00:00')));
    }

    // ------------------------------------------------------------ AttendanceRate

    public function test_rate_is_present_plus_late_over_total(): void
    {
        $this->assertSame(100, AttendanceRate::rate(8, 2, 10));
        $this->assertSame(80, AttendanceRate::rate(7, 1, 10));
        $this->assertSame(0, AttendanceRate::rate(0, 0, 0));
    }

    public function test_threshold_uses_at_least_operator(): void
    {
        $this->assertFalse(AttendanceRate::overThreshold(4, 5));
        $this->assertTrue(AttendanceRate::overThreshold(5, 5));
        $this->assertTrue(AttendanceRate::overThreshold(6, 5));
    }
}
