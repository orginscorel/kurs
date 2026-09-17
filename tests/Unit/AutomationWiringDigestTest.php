<?php

namespace Tests\Unit;

use App\Services\Notifications\DailyDigest;
use PHPUnit\Framework\TestCase;

class AutomationWiringDigestTest extends TestCase
{
    public function test_money_formatting_without_float(): void
    {
        $this->assertSame('12.345,50', DailyDigest::money('12345.50'));
        $this->assertSame('0,00', DailyDigest::money('0'));
        $this->assertSame('1.000.000,05', DailyDigest::money('1000000.05'));
        $this->assertSame('-250,00', DailyDigest::money('-250.00'));
    }

    public function test_morning_digest_lines(): void
    {
        $d = DailyDigest::morning([
            'date_label' => '16.09.2026', 'expected_today' => 180, 'absent_yesterday' => 12, 'absent_yesterday_lessons' => 30,
            'due_today_count' => 4, 'due_today_amount' => '14500.00', 'overdue_count' => 7, 'high_risk' => 15,
        ]);

        $this->assertSame('Günaydın · 16.09.2026 özeti', $d['title']);
        $this->assertStringContainsString('Bugün beklenen öğrenci: 180', $d['body']);
        $this->assertStringContainsString('Dün devamsızlık: 12 öğrenci (30 ders)', $d['body']);
        $this->assertStringContainsString('Bugün vadeli tahsilat: 4 taksit, 14.500,00 TL', $d['body']);
        $this->assertStringContainsString('Yüksek riskli öğrenci: 15', $d['body']);
        $this->assertStringContainsString('Gecikmiş taksit: 7', $d['body']);
        $this->assertSame('warning', $d['type']);
    }

    public function test_quiet_morning_is_info(): void
    {
        $d = DailyDigest::morning([
            'date_label' => '16.09.2026', 'expected_today' => 0, 'absent_yesterday' => 0, 'absent_yesterday_lessons' => 0,
            'due_today_count' => 0, 'due_today_amount' => '0', 'overdue_count' => 0, 'high_risk' => 0,
        ]);
        $this->assertStringContainsString('Dün devamsızlık yok', $d['body']);
        $this->assertStringContainsString('Bugün vadesi gelen taksit yok', $d['body']);
        $this->assertStringNotContainsString('Gecikmiş taksit', $d['body']);
        $this->assertSame('info', $d['type']);
    }

    public function test_evening_digest_counts_no_shows_and_collections(): void
    {
        $d = DailyDigest::evening([
            'date_label' => '16.09.2026', 'expected_today' => 10, 'arrived_today' => 3,
            'no_show_names' => ['Ali A', 'Ayşe B', 'Can C', 'Deniz D', 'Ece E'], 'absent_today' => 6,
            'collected_count' => 2, 'collected_amount' => '3000.00', 'voided_count' => 1,
        ]);
        $this->assertStringContainsString('Bugün gelmeyen: 7 öğrenci — Ali A, Ayşe B, Can C, Deniz D, Ece E ve 2 öğrenci daha', $d['body']);
        $this->assertStringContainsString('Tahsilat: 2 işlem, 3.000,00 TL', $d['body']);
        $this->assertStringContainsString('İptal edilen tahsilat: 1', $d['body']);
        $this->assertSame('attendance', $d['type']);
    }

    public function test_evening_all_arrived(): void
    {
        $d = DailyDigest::evening([
            'date_label' => '16.09.2026', 'expected_today' => 5, 'arrived_today' => 5, 'no_show_names' => [], 'absent_today' => 0,
            'collected_count' => 0, 'collected_amount' => '0', 'voided_count' => 0,
        ]);
        $this->assertStringContainsString('Beklenen öğrencilerin tamamı geldi', $d['body']);
        $this->assertStringContainsString('Bugün tahsilat yapılmadı', $d['body']);
        $this->assertSame('success', $d['type']);
    }
}
