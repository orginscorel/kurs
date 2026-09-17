<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Services\Finance\InstallmentPlanRules;
use PHPUnit\Framework\TestCase;

class FinancePlanRulesTest extends TestCase
{
    /** 12.000 TL net: 1. taksit ödendi, 2. kısmi (1.000 / 3.000 ödendi), 3-4 bekliyor. */
    private function existing(): array
    {
        return [
            ['id' => 1, 'amount' => '3000.00', 'paid_amount' => '3000.00', 'status' => 'paid', 'due_date' => '2026-09-01', 'has_allocations' => true],
            ['id' => 2, 'amount' => '3000.00', 'paid_amount' => '1000.00', 'status' => 'overdue', 'due_date' => '2026-10-01', 'has_allocations' => true],
            ['id' => 3, 'amount' => '3000.00', 'paid_amount' => '0.00', 'status' => 'pending', 'due_date' => '2026-11-01', 'has_allocations' => false],
            ['id' => 4, 'amount' => '3000.00', 'paid_amount' => '0.00', 'status' => 'pending', 'due_date' => '2026-12-01', 'has_allocations' => true],
        ];
    }

    public function test_split_keeps_total_and_removes_merged_rows(): void
    {
        // 3 ve 4 birleştirilip üç eşit parçaya bölünüyor: 6.000 → 2.000 × 3
        $plan = InstallmentPlanRules::restructure('12000.00', $this->existing(), [
            ['id' => 2, 'due_date' => '2026-10-15', 'amount' => '3000'],
            ['id' => 3, 'due_date' => '2026-11-01', 'amount' => '2000'],
            ['due_date' => '2026-12-01', 'amount' => '2000,00'],
            ['due_date' => '2027-01-01', 'amount' => '2000.00'],
        ]);

        $this->assertSame(['due_date' => '2026-10-15', 'amount' => '3000.00'], $plan['update'][2]);
        $this->assertCount(2, $plan['create']);
        $this->assertSame([], $plan['delete']);
        $this->assertSame([4], $plan['cancel'], 'İptal edilmiş tahsilat geçmişi olan taksit silinmez, iptal edilir');
        $this->assertSame('3000.00', $plan['locked_total']);
        $this->assertSame('9000.00', $plan['editable_total']);
        $this->assertSame('4000.00', $plan['paid_total']);
    }

    public function test_unallocated_pending_row_is_deleted(): void
    {
        $plan = InstallmentPlanRules::restructure('12000.00', $this->existing(), [
            ['id' => 2, 'due_date' => '2026-10-01', 'amount' => '3000'],
            ['id' => 4, 'due_date' => '2026-12-01', 'amount' => '6000'],
        ]);
        $this->assertSame([3], $plan['delete']);
        $this->assertSame([], $plan['cancel']);
    }

    public function test_total_must_equal_net_price_to_the_cent(): void
    {
        try {
            InstallmentPlanRules::restructure('12000.00', $this->existing(), [
                ['id' => 2, 'due_date' => '2026-10-01', 'amount' => '3000'],
                ['id' => 3, 'due_date' => '2026-11-01', 'amount' => '2999.99'],
                ['id' => 4, 'due_date' => '2026-12-01', 'amount' => '3000'],
            ]);
            $this->fail('Toplam eşleşmediği hâlde plan kabul edildi');
        } catch (BusinessRuleException $e) {
            $this->assertSame('plan_total_mismatch', $e->errorCode);
            $this->assertSame('0.01', $e->context['difference']);
            $this->assertSame('9000.00', $e->context['required_editable_total']);
        }
    }

    public function test_partial_installment_cannot_go_below_paid_part(): void
    {
        try {
            InstallmentPlanRules::restructure('12000.00', $this->existing(), [
                ['id' => 2, 'due_date' => '2026-10-01', 'amount' => '999.99'],
                ['id' => 3, 'due_date' => '2026-11-01', 'amount' => '5000.01'],
                ['id' => 4, 'due_date' => '2026-12-01', 'amount' => '3000'],
            ]);
            $this->fail('Kısmi taksit ödenen kısmın altına indirilebildi');
        } catch (BusinessRuleException $e) {
            $this->assertSame('plan_below_paid', $e->errorCode);
            $this->assertSame('1000.00', $e->context['paid']);
        }
    }

    public function test_partial_installment_cannot_be_removed(): void
    {
        try {
            InstallmentPlanRules::restructure('12000.00', $this->existing(), [
                ['id' => 3, 'due_date' => '2026-11-01', 'amount' => '4500'],
                ['id' => 4, 'due_date' => '2026-12-01', 'amount' => '4500'],
            ]);
            $this->fail('Kısmi ödenmiş taksit plandan çıkarılabildi');
        } catch (BusinessRuleException $e) {
            $this->assertSame('plan_partial_removed', $e->errorCode);
        }
    }

    public function test_paid_installment_is_locked(): void
    {
        try {
            InstallmentPlanRules::restructure('12000.00', $this->existing(), [
                ['id' => 1, 'due_date' => '2026-09-01', 'amount' => '3000'],
                ['id' => 2, 'due_date' => '2026-10-01', 'amount' => '3000'],
                ['id' => 3, 'due_date' => '2026-11-01', 'amount' => '3000'],
                ['id' => 4, 'due_date' => '2026-12-01', 'amount' => '3000'],
            ]);
            $this->fail('Ödenmiş taksit düzenlenebildi');
        } catch (BusinessRuleException $e) {
            $this->assertSame('plan_locked_installment', $e->errorCode);
        }
    }

    public function test_rejects_foreign_duplicate_zero_and_invalid_dates(): void
    {
        $cases = [
            'plan_unknown_installment' => [['id' => 99, 'due_date' => '2026-10-01', 'amount' => '9000']],
            'plan_duplicate_installment' => [['id' => 2, 'due_date' => '2026-10-01', 'amount' => '4500'], ['id' => 2, 'due_date' => '2026-10-01', 'amount' => '4500']],
            'plan_invalid_amount' => [['id' => 2, 'due_date' => '2026-10-01', 'amount' => '0'], ['due_date' => '2026-10-01', 'amount' => '9000']],
            'plan_invalid_date' => [['id' => 2, 'due_date' => '2026-02-30', 'amount' => '9000']],
        ];
        foreach ($cases as $code => $rows) {
            try {
                InstallmentPlanRules::restructure('12000.00', $this->existing(), $rows);
                $this->fail("{$code} beklenirken plan kabul edildi");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    public function test_discount_is_distributed_proportionally_with_cent_remainder(): void
    {
        // Kalanlar: 2.000 (id 2), 3.000 (id 3), 3.000 (id 4) = 8.000; indirim 1.000,01
        $rows = [
            2 => ['amount' => '3000.00', 'paid_amount' => '1000.00'],
            3 => ['amount' => '3000.00', 'paid_amount' => '0.00'],
            4 => ['amount' => '3000.00', 'paid_amount' => '0.00'],
        ];
        $result = InstallmentPlanRules::distributeDelta($rows, '-1000.01');

        $before = array_reduce($rows, fn ($s, $r) => bcadd($s, $r['amount'], 2), '0.00');
        $after = array_reduce($result['amounts'], fn ($s, $a) => bcadd($s, $a, 2), '0.00');
        $this->assertSame('1000.01', bcsub($before, $after, 2), 'Dağıtılan indirim kuruşu kuruşuna eşit olmalı');
        $this->assertSame('2750.00', $result['amounts'][2]);
        $this->assertSame('2625.00', $result['amounts'][3]);
        $this->assertSame('2624.99', $result['amounts'][4], 'Kuruş artığı sondaki taksite yazılır');
        $this->assertSame('0.00', $result['extra']);
    }

    public function test_discount_cannot_exceed_unpaid_balance_and_never_touches_paid_part(): void
    {
        $rows = [2 => ['amount' => '3000.00', 'paid_amount' => '1000.00']];

        $full = InstallmentPlanRules::distributeDelta($rows, '-2000');
        $this->assertSame('1000.00', $full['amounts'][2], 'Tamamı düşülünce tutar ödenen kısma eşitlenir');

        $this->expectException(BusinessRuleException::class);
        InstallmentPlanRules::distributeDelta($rows, '-2000.01');
    }

    public function test_price_increase_is_distributed_or_becomes_new_installment(): void
    {
        $rows = [3 => ['amount' => '1000.00', 'paid_amount' => '0.00'], 4 => ['amount' => '2000.00', 'paid_amount' => '0.00']];
        $result = InstallmentPlanRules::distributeDelta($rows, '100.00');
        $this->assertSame('1033.33', $result['amounts'][3]);
        $this->assertSame('2066.67', $result['amounts'][4]);

        $none = InstallmentPlanRules::distributeDelta([], '500');
        $this->assertSame('500.00', $none['extra']);
    }
}
