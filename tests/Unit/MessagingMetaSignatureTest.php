<?php

namespace Tests\Unit;

use App\Services\Messaging\MetaSignature;
use PHPUnit\Framework\TestCase;

class MessagingMetaSignatureTest extends TestCase
{
    public function test_sign_produces_sha256_prefixed_signature(): void
    {
        $signature = MetaSignature::sign('{"hello":"world"}', 'app-secret');

        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertSame(71, strlen($signature)); // "sha256=" + 64 hex karakter
    }

    public function test_verify_accepts_correct_signature(): void
    {
        $payload = '{"entry":[{"id":"123"}]}';
        $secret = 'my-app-secret';
        $signature = MetaSignature::sign($payload, $secret);

        $this->assertTrue(MetaSignature::verify($payload, $signature, $secret));
    }

    public function test_verify_rejects_tampered_payload(): void
    {
        $secret = 'my-app-secret';
        $signature = MetaSignature::sign('{"a":1}', $secret);

        $this->assertFalse(MetaSignature::verify('{"a":2}', $signature, $secret));
    }

    public function test_verify_rejects_wrong_secret(): void
    {
        $payload = '{"a":1}';
        $signature = MetaSignature::sign($payload, 'secret-a');

        $this->assertFalse(MetaSignature::verify($payload, $signature, 'secret-b'));
    }

    public function test_verify_rejects_missing_header_or_secret(): void
    {
        $this->assertFalse(MetaSignature::verify('{}', null, 'secret'));
        $this->assertFalse(MetaSignature::verify('{}', 'sha256=abc', ''));
    }
}
