<?php

namespace Tests\Unit;

use App\Services\Finance\AmountInWords;
use App\Services\Finance\ReceivableAging;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FinanceTextAndAgingTest extends TestCase
{
    public function test_amount_in_words_turkish_rules(): void
    {
        $this->assertSame('Sıfır Türk lirası', AmountInWords::lira('0'));
        $this->assertSame('Bir Türk lirası', AmountInWords::lira('1'));
        $this->assertSame('On Türk lirası elli kuruş', AmountInWords::lira('10.50'));
        $this->assertSame('Yüz Türk lirası', AmountInWords::lira('100'));
        $this->assertSame('Bin Türk lirası', AmountInWords::lira('1000'), '"bir bin" denmez');
        $this->assertSame('Bin yüz on bir Türk lirası', AmountInWords::lira('1111'));
        $this->assertSame('İki bin beş yüz Türk lirası', AmountInWords::lira('2500.00'));
        $this->assertSame('On iki bin üç yüz kırk beş Türk lirası altmış yedi kuruş', AmountInWords::lira('12345.67'));
        $this->assertSame('Yüz bin Türk lirası', AmountInWords::lira('100000'));
        $this->assertSame('Bir milyon iki yüz bin bir Türk lirası', AmountInWords::lira('1200001'));
        $this->assertSame('Bir milyon bin Türk lirası', AmountInWords::lira('1001000'));
        $this->assertSame('Dokuz yüz doksan dokuz Türk lirası doksan dokuz kuruş', AmountInWords::lira('999,99'));
        $this->assertSame('Beş bin Türk lirası beş kuruş', AmountInWords::lira('5.000,05'));
    }

    public function test_aging_bucket_boundaries(): void
    {
        $this->assertSame('current', ReceivableAging::bucketFor(-10));
        $this->assertSame('current', ReceivableAging::bucketFor(0));
        $this->assertSame('d0_30', ReceivableAging::bucketFor(1));
        $this->assertSame('d0_30', ReceivableAging::bucketFor(30));
        $this->assertSame('d31_60', ReceivableAging::bucketFor(31));
        $this->assertSame('d31_60', ReceivableAging::bucketFor(60));
        $this->assertSame('d61_90', ReceivableAging::bucketFor(61));
        $this->assertSame('d61_90', ReceivableAging::bucketFor(90));
        $this->assertSame('d90_plus', ReceivableAging::bucketFor(91));
    }

    public function test_days_overdue_counts_calendar_days(): void
    {
        $today = CarbonImmutable::parse('2026-03-01');
        $this->assertSame(1, ReceivableAging::daysOverdue('2026-02-28', $today));
        $this->assertSame(0, ReceivableAging::daysOverdue('2026-03-01', $today));
        $this->assertSame(-30, ReceivableAging::daysOverdue('2026-03-31', $today));
        $this->assertSame(365, ReceivableAging::daysOverdue('2025-03-01', $today));
    }

    public function test_aging_summary_totals_with_exact_cents(): void
    {
        $today = CarbonImmutable::parse('2026-09-15');
        $rows = [
            ['due_date' => '2026-09-20', 'remaining' => '1000.10'],   // vadesi gelmemiş
            ['due_date' => '2026-09-15', 'remaining' => '0.20'],      // bugün vadeli → gelmemiş
            ['due_date' => '2026-09-14', 'remaining' => '500.00'],    // 1 gün
            ['due_date' => '2026-08-16', 'remaining' => '250.33'],    // 30 gün
            ['due_date' => '2026-08-15', 'remaining' => '100.01'],    // 31 gün
            ['due_date' => '2026-06-17', 'remaining' => '75.00'],     // 90 gün
            ['due_date' => '2026-01-01', 'remaining' => '9999.99'],   // 90+
            (object) ['due_date' => '2026-01-01', 'remaining' => '0.00'], // kapanmış satır sayılmaz
        ];
        $s = ReceivableAging::summarize($rows, $today);

        $this->assertSame('1000.30', $s['buckets']['current']['amount']);
        $this->assertSame(2, $s['buckets']['current']['count']);
        $this->assertSame('750.33', $s['buckets']['d0_30']['amount']);
        $this->assertSame('100.01', $s['buckets']['d31_60']['amount']);
        $this->assertSame('75.00', $s['buckets']['d61_90']['amount']);
        $this->assertSame('9999.99', $s['buckets']['d90_plus']['amount']);
        $this->assertSame('11925.63', $s['total']);
        $this->assertSame('10925.33', $s['overdue']);
    }

    public function test_sql_condition_matches_php_boundaries_and_rejects_injection(): void
    {
        $this->assertSame("DATEDIFF('2026-09-15', i.due_date) BETWEEN 1 AND 30", ReceivableAging::sqlCondition('d0_30', 'i.due_date', '2026-09-15'));
        $this->assertSame("DATEDIFF('2026-09-15', i.due_date) > 90", ReceivableAging::sqlCondition('d90_plus', 'i.due_date', '2026-09-15'));

        $this->expectException(\InvalidArgumentException::class);
        ReceivableAging::sqlCondition('d0_30', 'i.due_date', "2026-09-15'; DROP TABLE x; --");
    }
}
