<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Support\Crm\LeadStages;
use PHPUnit\Framework\TestCase;

class CrmLeadStageTest extends TestCase
{
    public function test_normal_stage_transition_is_allowed(): void
    {
        LeadStages::assertTransition('new', 'called', null, false);
        LeadStages::assertTransition('called', 'meeting_scheduled', null, false);
        $this->assertTrue(true);
    }

    public function test_same_stage_is_a_no_op(): void
    {
        LeadStages::assertTransition('met', 'met', null, false);
        $this->assertTrue(true);
    }

    public function test_direct_transition_to_won_is_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Kayda dönüştür');
        LeadStages::assertTransition('offered', 'won', null, false);
    }

    public function test_transition_to_lost_requires_reason(): void
    {
        $this->expectException(BusinessRuleException::class);
        LeadStages::assertTransition('offered', 'lost', '', false);
    }

    public function test_transition_to_lost_with_reason_is_allowed(): void
    {
        LeadStages::assertTransition('offered', 'lost', 'Fiyat yüksek bulundu.', false);
        $this->assertTrue(true);
    }

    public function test_converted_lead_cannot_be_moved(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('dönüşmüş');
        LeadStages::assertTransition('won', 'call_again', null, true);
    }

    public function test_invalid_target_stage_is_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        LeadStages::assertTransition('new', 'not_a_stage', null, false);
    }

    public function test_conversion_rate_calculation(): void
    {
        $this->assertSame(['rate' => 25.0, 'won' => 5, 'total' => 20], LeadStages::conversionRate(5, 20));
        $this->assertSame(['rate' => 0.0, 'won' => 0, 'total' => 0], LeadStages::conversionRate(0, 0));
        $this->assertSame(33.3, LeadStages::conversionRate(1, 3)['rate']);
    }

    public function test_positions_reindexes_ordered_ids_from_zero(): void
    {
        $this->assertSame([10 => 0, 20 => 1, 30 => 2], LeadStages::positions([10, 20, 30]));
    }
}
