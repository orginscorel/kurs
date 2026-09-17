<?php

namespace Tests\Unit;

use App\Support\Attendance\DeviceDataGuard;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Denetim 2026-09-16: cihaz verisi yokken otomatik GELMEDİ yazılmaz. */
class AttendanceDeviceGuardTest extends TestCase
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-16 10:00:00');
    }

    public function test_offline_devices_pause_auto_absence(): void
    {
        // Denetimdeki durum: cihazlar 14.09'dan beri sessiz, bugün hiç giriş yok.
        $lastSeen = [CarbonImmutable::parse('2026-09-14 23:47:00'), CarbonImmutable::parse('2026-09-14 23:40:00')];

        $this->assertSame('devices_silent', DeviceDataGuard::pauseReason($lastSeen, 0, null, $this->now(), 30));
        $this->assertTrue(DeviceDataGuard::shouldNotify('devices_silent'));
    }

    public function test_devices_silent_beyond_threshold_even_with_morning_entries(): void
    {
        $lastSeen = [$this->now()->subMinutes(45)];

        $this->assertSame('devices_silent', DeviceDataGuard::pauseReason($lastSeen, 80, $this->now()->subMinutes(50), $this->now(), 30));
        // Son giriş eşik içindeyse veri akıyor sayılır (nabız gecikmiş olabilir)
        $this->assertNull(DeviceDataGuard::pauseReason($lastSeen, 80, $this->now()->subMinutes(5), $this->now(), 30));
    }

    public function test_alive_device_with_no_entries_today_pauses(): void
    {
        $this->assertSame('no_entries', DeviceDataGuard::pauseReason([$this->now()->subMinute()], 0, null, $this->now(), 30));
        $this->assertTrue(DeviceDataGuard::shouldNotify('no_entries'));
    }

    public function test_alive_device_with_entries_allows_auto_absence(): void
    {
        $lastSeen = [null, $this->now()->subMinutes(2)];

        $this->assertNull(DeviceDataGuard::pauseReason($lastSeen, 12, $this->now()->subHour(), $this->now(), 30));
        $this->assertNull(DeviceDataGuard::pauseReason([$this->now()->subMinutes(30)], 1, null, $this->now(), 30));
    }

    public function test_branch_without_devices(): void
    {
        // Cihazsız şube: giriş verisi yoksa durur ama her gün uyarı üretmez
        $this->assertSame('no_devices', DeviceDataGuard::pauseReason([], 0, null, $this->now()));
        $this->assertFalse(DeviceDataGuard::shouldNotify('no_devices'));
        // Kiosk/QR girişleri akıyorsa otomatik yoklama çalışır
        $this->assertNull(DeviceDataGuard::pauseReason([], 5, $this->now()->subHours(2), $this->now()));
    }

    public function test_never_seen_devices_count_as_silent(): void
    {
        $this->assertSame('devices_silent', DeviceDataGuard::pauseReason([null, null], 0, null, $this->now()));
    }

    public function test_message_mentions_threshold(): void
    {
        $this->assertStringContainsString('45 dakikadır', DeviceDataGuard::message('devices_silent', 45));
    }
}
