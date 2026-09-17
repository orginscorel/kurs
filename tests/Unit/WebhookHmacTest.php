<?php

namespace Tests\Unit;

use App\Services\Webhooks\WebhookSigner;
use PHPUnit\Framework\TestCase;

class WebhookHmacTest extends TestCase
{
    public function test_sign_and_verify_round_trip(): void
    {
        $payload = json_encode(['event' => 'payment.received', 'student_id' => 42]);
        $secret = 'whsec_test123';

        $signature = WebhookSigner::sign($payload, $secret);

        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertTrue(WebhookSigner::verify($payload, $signature, $secret));
    }

    public function test_verify_fails_on_any_payload_change(): void
    {
        $secret = 'whsec_test123';
        $signature = WebhookSigner::sign('{"a":1}', $secret);

        $this->assertFalse(WebhookSigner::verify('{"a":2}', $signature, $secret));
    }

    public function test_verify_fails_on_wrong_secret(): void
    {
        $payload = '{"event":"student.entry"}';
        $signature = WebhookSigner::sign($payload, 'secret-1');

        $this->assertFalse(WebhookSigner::verify($payload, $signature, 'secret-2'));
    }
}
