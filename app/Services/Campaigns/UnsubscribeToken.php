<?php

namespace App\Services\Campaigns;

/**
 * Abonelikten çıkma bağlantısı için imzalı jeton (oturum gerektirmez, süresi yoktur).
 * Biçim: base64url(json) "." hmac-sha256 (ilk 32 hex). Anahtar APP_KEY'den türetilir;
 * jeton içeriği değiştirilirse imza tutmaz.
 */
final class UnsubscribeToken
{
    public static function make(int $branchId, string $channel, ?string $recipientType, ?int $recipientId, string $address): string
    {
        $payload = self::b64(json_encode([
            'b' => $branchId, 'c' => $channel, 't' => $recipientType, 'i' => $recipientId, 'a' => $address,
        ], JSON_UNESCAPED_UNICODE));

        return $payload.'.'.self::sign($payload);
    }

    /** @return array{branch_id:int, channel:string, recipient_type:?string, recipient_id:?int, address:string}|null */
    public static function parse(string $token): ?array
    {
        if (! preg_match('/^([A-Za-z0-9_-]{8,1024})\.([a-f0-9]{32})$/', $token, $m)) {
            return null;
        }
        if (! hash_equals(self::sign($m[1]), $m[2])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($m[1], '-_', '+/'), true), true);
        if (! is_array($data) || ! isset($data['b'], $data['c'], $data['a']) || ! in_array($data['c'], ['sms', 'email'], true)) {
            return null;
        }

        return [
            'branch_id' => (int) $data['b'],
            'channel' => (string) $data['c'],
            'recipient_type' => isset($data['t']) ? (string) $data['t'] : null,
            'recipient_id' => isset($data['i']) ? (int) $data['i'] : null,
            'address' => (string) $data['a'],
        ];
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/abonelik/'.$token;
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, 'kurs-unsubscribe|'.config('app.key')), 0, 32);
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
