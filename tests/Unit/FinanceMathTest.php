<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Services\Finance\EnrollmentService;
use App\Services\Finance\PaymentService;
use App\Support\Money;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FinanceMathTest extends TestCase
{
    public function test_plan_sums_exactly_to_net_price_with_remainder_on_last(): void
    {
        $plan = (new EnrollmentService)->buildPlan('10000.00', '0.00', 3, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-15'));

        $this->assertCount(3, $plan);
        $this->assertSame('3333.33', $plan[0]['amount']);
        $this->assertSame('3333.34', $plan[2]['amount']);
        $this->assertSame('10000.00', array_reduce($plan, fn ($s, $r) => bcadd($s, $r['amount'], 2), '0.00'));
        $this->assertSame(['2026-10-15', '2026-11-15', '2026-12-15'], array_column($plan, 'due_date'));
    }

    public function test_plan_with_down_payment_due_on_enrollment_day(): void
    {
        $plan = (new EnrollmentService)->buildPlan('60000.00', '10000.00', 5, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-15'));

        $this->assertCount(6, $plan);
        $this->assertSame(['due_date' => '2026-09-01', 'amount' => '10000.00'], $plan[0]);
        $this->assertSame('10000.00', $plan[5]['amount']);
        $this->assertSame('2027-02-15', $plan[5]['due_date']);
    }

    public function test_month_end_due_dates_do_not_overflow(): void
    {
        $plan = (new EnrollmentService)->buildPlan('3000.00', '0.00', 3, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($plan, 'due_date'));
    }

    public function test_down_payment_cannot_exceed_net(): void
    {
        $this->expectException(BusinessRuleException::class);
        (new EnrollmentService)->buildPlan('1000.00', '1500.00', 1, CarbonImmutable::today(), CarbonImmutable::today());
    }

    public function test_money_parses_turkish_format_and_never_uses_float_rounding(): void
    {
        $this->assertSame('5000.50', Money::of('5.000,50'));
        $this->assertSame('1234.00', Money::of('1234'));
        $this->assertSame('0.30', bcadd(Money::of('0.1'), Money::of('0.2'), 2));
        $this->assertSame('5.000,50', Money::format('5000.5'));
    }

    public function test_installment_status_rules(): void
    {
        $future = CarbonImmutable::today()->addDays(5);
        $past = CarbonImmutable::today()->subDay();

        $this->assertSame('paid', PaymentService::statusFor('1000.00', '1000.00', $past));
        $this->assertSame('partial', PaymentService::statusFor('1000.00', '400.00', $future));
        $this->assertSame('pending', PaymentService::statusFor('1000.00', '0.00', $future));
        $this->assertSame('overdue', PaymentService::statusFor('1000.00', '400.00', $past));
        $this->assertSame('pending', PaymentService::statusFor('1000.00', '0.00', CarbonImmutable::today()));
    }

    public function test_national_id_checksum(): void
    {
        $this->assertTrue(Sensitive::isValidNationalId('10000000146'));
        $this->assertFalse(Sensitive::isValidNationalId('10000000147'));
        $this->assertFalse(Sensitive::isValidNationalId('01234567890'));
    }

    public function test_phone_normalization(): void
    {
        $this->assertSame('905321112233', Sensitive::normalizePhone('0532 111 22 33'));
        $this->assertSame('905321112233', Sensitive::normalizePhone('+90 (532) 111-22-33'));
        $this->assertSame('905321112233', Sensitive::normalizePhone('5321112233'));
        $this->assertNull(Sensitive::normalizePhone('123'));
    }
}
