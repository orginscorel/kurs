<?php

namespace App\Services\Devices\Drivers\Yt33\Push;

use App\Services\Devices\Terminal\PushListener;
use Illuminate\Support\Facades\Log;

/**
 * YT33 "Server / Push" kipi: cihaz köprüye (bu Mac, varsayılan 7005) bağlanır ve HTTP/1.0 + JSON gönderir
 * (çerçeve: RealtimeProtocol). Ortak dinleyicinin üstüne üç şey ekler:
 *   1. Ham kayda yazılmadan önce biyometrik şablon gizlenir.
 *   2. İstek işlenir (okutma → yoklama/PDKS, kullanıcı kaydı → PDKS kişisi) — RealtimeIngest.
 *   3. Cihaza beklediği onay döner (response_code: OK + trans_id); onaysız cihaz aynı isteği yineler.
 * İşleme hatası onayı engellemez: ham paket saklandığı için `kurs:terminal-isle` ile yeniden işlenebilir.
 */
class Server extends PushListener
{
    private ?RealtimeIngest $ingest = null;

    protected function redact(string $bytes): array
    {
        return RealtimeProtocol::redact($bytes);
    }

    protected function onHttpRequest(string $request, string $remoteIp, ?int $packetId): ?string
    {
        $parsed = RealtimeProtocol::parse($request);

        if ($parsed === null) {
            return null;
        }

        try {
            $this->ingest()->handle($parsed, $remoteIp, $packetId);
        } catch (\Throwable $e) {
            report($e);
            Log::channel('terminal')->error('YT33 push işlenemedi', ['paket' => $packetId, 'hata' => $e->getMessage()]);
        }

        return RealtimeProtocol::replyFor($parsed);
    }

    public function ingest(): RealtimeIngest
    {
        return $this->ingest ??= app(RealtimeIngest::class);
    }
}
