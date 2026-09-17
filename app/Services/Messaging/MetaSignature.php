<?php

namespace App\Services\Messaging;

/**
 * Meta (WhatsApp Cloud API) webhook imza doğrulaması.
 * Meta, gövdeyi App Secret ile HMAC-SHA256 imzalar ve `X-Hub-Signature-256: sha256=…` başlığında gönderir.
 */
class MetaSignature
{
    public static function sign(string $payload, string $appSecret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $appSecret);
    }

    public static function verify(string $payload, ?string $header, string $appSecret): bool
    {
        if (! $header || $appSecret === '') {
            return false;
        }

        $expected = self::sign($payload, $appSecret);

        return hash_equals($expected, $header);
    }
}
