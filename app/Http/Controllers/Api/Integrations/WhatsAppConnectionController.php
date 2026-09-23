<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Api\ApiController;
use App\Models\Integration;
use App\Services\Communication\CommunicationAudit;
use App\Services\Messaging\Whatsapp\WwebjsClient;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * WhatsApp QR (kalıcı oturum) bağlantı yönetimi: durum, QR eşleştirme, kes ve hız/limit ayarları.
 * Bot sunucu servisidir ve sırlar cihaza inmez (Integration=LOCAL); bu yüzden tüm uçlar web.only'dir.
 * QR görseli http bottan çekilip https panele PROXY'lenir (mixed-content/CORS olmaz).
 */
class WhatsAppConnectionController extends ApiController
{
    private function integration(): ?Integration
    {
        $branchId = app(BranchContext::class)->require();

        return Integration::query()->where('branch_id', $branchId)->where('kind', 'whatsapp')->first();
    }

    /** Yapılandırma + canlı oturum durumu; Integration.status'u canlı duruma göre günceller. */
    public function show(): JsonResponse
    {
        $integration = $this->integration();
        if (! $integration || $integration->provider !== 'wwebjs') {
            return response()->json([
                'configured' => false,
                'provider' => $integration?->provider,
                'message' => 'WhatsApp QR sağlayıcısı seçilip bot adresi kaydedilmemiş. Entegrasyonlar ekranından "WhatsApp QR (kalıcı oturum)" seçip bot adresi ve jetonu girin.',
            ]);
        }

        $config = $integration->config();
        $client = new WwebjsClient($config);
        $status = $client->status();

        // Canlı duruma göre entegrasyon durumunu senkla (bağlıysa connected, değilse disconnected).
        if ($status['ok']) {
            $integration->forceFill([
                'status' => $status['connected'] ? 'connected' : 'disconnected',
                'last_error' => $status['connected'] ? null : $integration->last_error,
                'last_checked_at' => now(),
            ])->save();
        }

        return response()->json([
            'configured' => true,
            'reachable' => $status['ok'],
            'connected' => $status['connected'],
            'state' => $status['state'],
            'phone' => $status['phone'],
            'is_enabled' => (bool) $integration->is_enabled,
            'session_id' => $client->sessionId(),
            'error' => $status['error'],
            'rate' => $this->rate($config),
        ]);
    }

    /** Oturumu başlatır (QR üretimi için) — henüz bağlı değilse. */
    public function start(): JsonResponse
    {
        $integration = $this->requireWwebjs();
        $client = new WwebjsClient($integration->config());

        $status = $client->status();
        if ($status['connected']) {
            return response()->json(['ok' => true, 'connected' => true, 'message' => 'Oturum zaten bağlı.']);
        }

        $client->start();
        CommunicationAudit::log('communication.whatsapp.start', 'WhatsApp QR oturumu başlattı.', $integration);

        return response()->json(['ok' => true, 'connected' => false, 'message' => 'Oturum başlatıldı. QR kodu okutun.']);
    }

    /** Ham QR (dataURL) + bağlantı durumu. */
    public function qr(): JsonResponse
    {
        $integration = $this->requireWwebjs();
        $client = new WwebjsClient($integration->config());

        $status = $client->status();
        if ($status['connected']) {
            return response()->json(['ok' => true, 'connected' => true, 'qr' => null]);
        }

        $qr = $client->qr();

        return response()->json([
            'ok' => $qr['ok'],
            'connected' => false,
            'qr' => $qr['qr'],
            'message' => $qr['ok'] ? null : 'QR henüz hazır değil, birkaç saniye sonra tekrar deneyin.',
        ]);
    }

    /** QR PNG proxy: botun /session/qr/{sid}/image çıktısını tarayıcıya ikili aktarır. */
    public function qrImage(): Response
    {
        $integration = $this->integration();
        abort_unless($integration && $integration->provider === 'wwebjs', 404);

        $client = new WwebjsClient($integration->config());
        $image = $client->qrImage();
        abort_unless($image['ok'] && $image['bytes'] !== null, 404, 'QR görseli alınamadı.');

        return response($image['bytes'], 200, [
            'Content-Type' => $image['content_type'],
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /** Oturumu kapatır (numarayı çıkarır). */
    public function disconnect(): JsonResponse
    {
        $integration = $this->requireWwebjs();
        $client = new WwebjsClient($integration->config());
        $client->terminate();

        $integration->forceFill(['status' => 'disconnected', 'last_checked_at' => now()])->save();
        CommunicationAudit::log('communication.whatsapp.disconnect', 'WhatsApp oturumunu kapattı.', $integration);

        return $this->ok('WhatsApp oturumu kapatıldı.');
    }

    /** Anti-ban / hız ayarları: gönderim gecikmesi, jitter, günlük limit, opt-out listesi. */
    public function saveRate(Request $request): JsonResponse
    {
        $integration = $this->requireWwebjs();

        $data = $request->validate([
            'send_delay_ms' => ['nullable', 'integer', 'min:0', 'max:120000'],
            'jitter_ms' => ['nullable', 'integer', 'min:0', 'max:120000'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'opt_out' => ['nullable', 'string', 'max:20000'],
        ]);

        $config = $integration->config();
        foreach (['send_delay_ms', 'jitter_ms', 'daily_limit'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $config[$key] = (int) $data[$key];
            }
        }
        if (array_key_exists('opt_out', $data)) {
            $config['opt_out'] = (string) ($data['opt_out'] ?? '');
        }
        $integration->setConfig($config);
        $integration->save();

        CommunicationAudit::log('communication.whatsapp.rate', 'WhatsApp hız/limit ayarlarını güncelledi.', $integration);

        return response()->json(['message' => 'Ayarlar kaydedildi.', 'rate' => $this->rate($config)]);
    }

    private function requireWwebjs(): Integration
    {
        $integration = $this->integration();
        abort_unless($integration && $integration->provider === 'wwebjs', 422, 'Önce WhatsApp QR sağlayıcısını ve bot adresini kaydedin.');

        return $integration;
    }

    /** Varsayılanlarıyla hız ayarları. */
    private function rate(array $config): array
    {
        return [
            'send_delay_ms' => (int) ($config['send_delay_ms'] ?? 4000),
            'jitter_ms' => (int) ($config['jitter_ms'] ?? 3000),
            'daily_limit' => (int) ($config['daily_limit'] ?? 800),
            'opt_out' => (string) ($config['opt_out'] ?? ''),
        ];
    }
}
