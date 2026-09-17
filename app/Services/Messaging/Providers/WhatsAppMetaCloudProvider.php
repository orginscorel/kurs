<?php

namespace App\Services\Messaging\Providers;

use App\Models\OutboundMessage;
use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\TestResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Meta (WhatsApp) Cloud API. Serbest metin yalnızca son 24 saatte müşteri yazdıysa
 * gönderilebilir; bu pencerenin dışında Meta onaylı şablon (`provider_template`) gerekir.
 * config: phone_number_id, access_token, app_secret, business_account_id, verify_token, template_language
 */
class WhatsAppMetaCloudProvider implements MessageProvider
{
    private const BASE = 'https://graph.facebook.com/v20.0';

    public function send(OutboundMessage $message, array $config): ProviderResult
    {
        $phoneNumberId = $config['phone_number_id'] ?? null;
        $token = $config['access_token'] ?? null;

        if (! $phoneNumberId || ! $token) {
            return ProviderResult::fail('WhatsApp (Meta) yapılandırması eksik: numara kimliği veya erişim jetonu yok.');
        }

        $to = preg_replace('/\D/', '', $message->to);
        $payload = $this->buildPayload($message, $to, $config);

        try {
            $response = Http::withToken($token)->timeout(15)->retry(0, 0)
                ->post(self::BASE."/{$phoneNumberId}/messages", $payload);
        } catch (\Throwable $e) {
            return ProviderResult::fail('WhatsApp sağlayıcısına ulaşılamadı: '.$e->getMessage());
        }

        if ($response->failed()) {
            $err = $response->json('error.message') ?? $response->body();

            return ProviderResult::fail('WhatsApp gönderimi reddedildi: '.mb_substr((string) $err, 0, 400));
        }

        return ProviderResult::ok($response->json('messages.0.id'));
    }

    private function buildPayload(OutboundMessage $message, string $to, array $config): array
    {
        if ($message->template_key && ($config['use_approved_templates'] ?? true) && $message->provider_template ?? null) {
            // Meta onaylı şablon adı MessageTemplate->provider_template üzerinden taşınır (queue() sırasında kopyalanır).
        }

        if ($message->media_path && Storage::disk('local')->exists($message->media_path)) {
            $link = $this->uploadMediaLink($message, $config);
            if ($link) {
                return [
                    'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'image',
                    'image' => ['link' => $link, 'caption' => mb_substr($message->body, 0, 1024)],
                ];
            }
        }

        return [
            'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message->body],
        ];
    }

    /** Görsel rapor kartı gibi medyalar herkese açık bir URL üzerinden bağlanır. */
    private function uploadMediaLink(OutboundMessage $message, array $config): ?string
    {
        $base = rtrim((string) ($config['public_media_base_url'] ?? config('app.url')), '/');

        return $base.'/storage/'.ltrim($message->media_path, '/');
    }

    public function testConnection(array $config): TestResult
    {
        $phoneNumberId = $config['phone_number_id'] ?? null;
        $token = $config['access_token'] ?? null;

        if (! $phoneNumberId || ! $token) {
            return TestResult::fail('Numara kimliği ve erişim jetonu girilmeli.');
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get(self::BASE."/{$phoneNumberId}", ['fields' => 'display_phone_number,verified_name']);
        } catch (\Throwable $e) {
            return TestResult::fail('Meta API\'ye ulaşılamadı: '.$e->getMessage());
        }

        if ($response->failed()) {
            return TestResult::fail('Meta doğrulaması başarısız: '.mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 300));
        }

        return TestResult::ok('Bağlantı doğrulandı: '.($response->json('display_phone_number') ?? $phoneNumberId), $response->json() ?? []);
    }
}
