<?php

namespace App\Services\Webhooks;

/**
 * Giden webhook imzası: HMAC-SHA256(gövde, gizli anahtar) → `X-Erbaa-Signature: sha256=…`.
 * Alıcı sistem aynı yöntemle gövdeyi tekrar imzalayıp karşılaştırmalıdır.
 */
class WebhookSigner
{
    public static function sign(string $payload, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $payload, string $signature, string $secret): bool
    {
        return hash_equals(self::sign($payload, $secret), $signature);
    }
}
