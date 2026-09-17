<?php

namespace App\Http\Controllers\Api\Communication;

use App\Models\Integration;
use App\Models\OutboundMessage;
use App\Services\Messaging\MetaSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * OTURUMSUZ uç: WhatsApp (Meta Cloud API) sağlayıcı webhook'ları — doğrulama (GET) ve
 * teslim/okundu durum bildirimleri (POST). Kimlik doğrulaması Meta'nın kendi imza/doğrulama
 * jetonu ile yapılır (oturum/izin YOKTUR — bu yüzden imza kontrolü zorunludur).
 */
class ProviderWebhookController
{
    private const RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    /** Meta webhook kurulum doğrulaması: hub.mode=subscribe&hub.verify_token=…&hub.challenge=… */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        $matches = Integration::query()->where('kind', 'whatsapp')->get()
            ->contains(fn (Integration $i) => ($i->config()['verify_token'] ?? null) === $token && $token !== null);

        if ($mode === 'subscribe' && $matches && $challenge !== null) {
            return response($challenge, 200);
        }

        return response('Doğrulama başarısız.', 403);
    }

    public function statusCallback(Request $request): Response
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');

        $integration = Integration::query()->where('kind', 'whatsapp')->get()
            ->first(fn (Integration $i) => MetaSignature::verify($raw, $signature, (string) ($i->config()['app_secret'] ?? '')));

        if (! $integration) {
            Log::warning('WhatsApp webhook imzası doğrulanamadı.');

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($raw, true) ?? [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $this->applyStatus($status);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function applyStatus(array $status): void
    {
        $messageId = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;
        if (! $messageId || ! isset(self::RANK[$newStatus])) {
            return;
        }

        $message = OutboundMessage::query()->where('provider_message_id', $messageId)->first();
        if (! $message) {
            return;
        }

        $currentRank = self::RANK[$message->status] ?? 0;
        if (self::RANK[$newStatus] < $currentRank) {
            return; // eski durum geç geldi — geri sarma
        }

        $timestamp = isset($status['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $status['timestamp']) : now();
        $update = ['status' => $newStatus];
        if ($newStatus === 'delivered') {
            $update['delivered_at'] = $timestamp;
        } elseif ($newStatus === 'read') {
            $update['read_at'] = $timestamp;
            $update['delivered_at'] ??= $message->delivered_at ?? $timestamp;
        } elseif ($newStatus === 'failed') {
            $update['error'] = $status['errors'][0]['title'] ?? 'Sağlayıcı teslim hatası bildirdi.';
        }

        $message->forceFill($update)->save();
    }
}
