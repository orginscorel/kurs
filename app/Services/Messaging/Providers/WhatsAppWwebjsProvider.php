<?php

namespace App\Services\Messaging\Providers;

use App\Models\OutboundMessage;
use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\TestResult;
use App\Services\Messaging\Whatsapp\WwebjsClient;

/**
 * whatsapp-web.js (QR eşleştirmeli, kalıcı oturum) sağlayıcısı. Numarayı QR ile bağlarsınız;
 * mesajlar botun kalıcı oturumu üzerinden gider. Yapılandırma `Integration->config()` içindedir:
 *   base_url, api_key (bot token), session_id (varsayılan 'kurs').
 * Medya (PDF makbuz vb.): `OutboundMessage->media_path` mutlak bir URL ise gönderilir.
 */
class WhatsAppWwebjsProvider implements MessageProvider
{
    public function send(OutboundMessage $message, array $config): ProviderResult
    {
        $client = new WwebjsClient($config);
        if (! $client->isConfigured()) {
            return ProviderResult::fail('WhatsApp bot adresi yapılandırılmamış.');
        }

        $media = is_string($message->media_path ?? null) && preg_match('#^https?://#', $message->media_path)
            ? $message->media_path : null;

        $res = $client->sendMessage((string) $message->to, (string) $message->body, $media);

        return $res['ok'] ? ProviderResult::ok($res['id']) : ProviderResult::fail($res['error'] ?? 'WhatsApp gönderimi başarısız.');
    }

    public function testConnection(array $config): TestResult
    {
        $client = new WwebjsClient($config);
        if (! $client->isConfigured()) {
            return TestResult::fail('WhatsApp bot adresi (URL) yapılandırılmamış.');
        }

        $status = $client->status();
        if (! $status['ok']) {
            return TestResult::fail('Bota ulaşılamadı: '.($status['error'] ?? 'bilinmeyen hata'));
        }
        if (! $status['connected']) {
            return TestResult::fail('Bot ulaşılabilir ama oturum bağlı değil. "WhatsApp Bağlantısı" ekranından QR okutun. (Durum: '.$status['state'].')');
        }

        $phone = $status['phone'] !== '' ? ' ('.$status['phone'].')' : '';

        return TestResult::ok('WhatsApp oturumu bağlı'.$phone.'.');
    }
}
