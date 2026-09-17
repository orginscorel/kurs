<?php

namespace Tests\Unit;

use App\Services\Automation\NetDrop;
use App\Services\Automation\RiskTransition;
use PHPUnit\Framework\TestCase;

class AutomationWiringRiskTest extends TestCase
{
    public function test_escalation_to_high_only_on_transition(): void
    {
        $this->assertTrue(RiskTransition::escalatedToHigh('medium', 'high'));
        $this->assertTrue(RiskTransition::escalatedToHigh('low', 'high'));
        $this->assertTrue(RiskTransition::escalatedToHigh(null, 'high'));
        $this->assertFalse(RiskTransition::escalatedToHigh('high', 'high'), 'her gece tekrar etmemeli');
        $this->assertFalse(RiskTransition::escalatedToHigh('high', 'medium'));
        $this->assertFalse(RiskTransition::escalatedToHigh('low', 'medium'));
    }

    public function test_generic_escalation_order(): void
    {
        $this->assertTrue(RiskTransition::isEscalation('low', 'medium'));
        $this->assertFalse(RiskTransition::isEscalation('medium', 'low'));
        $this->assertFalse(RiskTransition::isEscalation('high', 'high'));
    }

    public function test_net_drop_requires_absolute_and_relative_threshold(): void
    {
        $this->assertTrue(NetDrop::isSignificant(60.0, 54.0));   // -6 net, %10
        $this->assertFalse(NetDrop::isSignificant(60.0, 56.5));  // -3.5 net
        $this->assertFalse(NetDrop::isSignificant(80.0, 74.5));  // -5.5 net ama %6.9
        $this->assertFalse(NetDrop::isSignificant(null, 20.0));  // önceki sınav yok
        $this->assertFalse(NetDrop::isSignificant(40.0, 50.0));  // yükseliş
        $this->assertSame(-6.0, NetDrop::delta(60.0, 54.0));
    }
}
