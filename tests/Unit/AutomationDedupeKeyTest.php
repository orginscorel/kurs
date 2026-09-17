<?php

namespace Tests\Unit;

use App\Services\Automation\DedupeKey;
use PHPUnit\Framework\TestCase;

class AutomationDedupeKeyTest extends TestCase
{
    public function test_build_joins_short_parts_verbatim(): void
    {
        $this->assertSame('rule:12:attendance.absent:7:absent:99', DedupeKey::build(['rule', 12, 'attendance.absent', 7, 'absent:99']));
    }

    public function test_build_is_deterministic(): void
    {
        $a = DedupeKey::build(['automation', 5, 'payment.received', 3, 'whatsapp', 'guardian', 8, 'payment:9']);
        $b = DedupeKey::build(['automation', 5, 'payment.received', 3, 'whatsapp', 'guardian', 8, 'payment:9']);

        $this->assertSame($a, $b);
    }

    public function test_build_produces_different_keys_for_different_parts(): void
    {
        $a = DedupeKey::build(['rule', 1, 'trigger', 1]);
        $b = DedupeKey::build(['rule', 2, 'trigger', 1]);

        $this->assertNotSame($a, $b);
    }

    public function test_build_hashes_and_truncates_when_over_max_length(): void
    {
        $longSuffix = str_repeat('x', 200);
        $key = DedupeKey::build(['rule', 1, 'trigger', $longSuffix], 120);

        $this->assertLessThanOrEqual(120, strlen($key));
        $this->assertStringContainsString(':', $key);
    }

    public function test_null_parts_become_empty_strings(): void
    {
        $this->assertSame('a::c', DedupeKey::build(['a', null, 'c']));
    }
}
