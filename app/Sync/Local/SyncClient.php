<?php

namespace App\Sync\Local;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Yerel düğümün sunucu istemcisi (Bearer cihaz jetonu). Hatalar SyncHttpException olarak döner.
 */
class SyncClient
{
    public function __construct(private readonly LocalState $state) {}

    public function serverUrl(): ?string
    {
        $url = config('sync.server_url') ?: $this->state->get('server_url');

        return $url ? rtrim((string) $url, '/') : null;
    }

    public function token(): ?string
    {
        if ($t = config('sync.device_token')) {
            return (string) $t;
        }
        $enc = $this->state->get('device_token');
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isPaired(): bool
    {
        return $this->serverUrl() !== null && $this->token() !== null;
    }

    private function http(): PendingRequest
    {
        // Yerel düğümde dış istek yasağı: yalnız eşitleme sunucusu
        Http::allowStrayRequests([$this->serverUrl().'/*']);

        return Http::baseUrl($this->serverUrl().'/api/v1/')
            ->acceptJson()
            ->withToken((string) $this->token())
            ->withHeaders(['X-App-Version' => (string) config('app.version', 'yerel-1')])
            ->timeout((int) config('sync.timeout_seconds', 30))
            ->connectTimeout(10);
    }

    /** @return array<string, mixed> */
    private function decode(Response $r, string $what): array
    {
        if ($r->failed()) {
            $errors = $r->json('errors');
            throw new SyncHttpException(
                (string) ((is_array($errors) ? collect($errors)->flatten()->first() : null) ?: $r->json('message') ?: "$what başarısız (HTTP {$r->status()})"),
                $r->status(),
                (string) ($r->json('error_code') ?? ''),
            );
        }

        return (array) $r->json();
    }

    private function call(callable $fn, string $what): array
    {
        try {
            return $this->decode($fn($this->http()), $what);
        } catch (ConnectionException $e) {
            \Illuminate\Support\Facades\Log::warning('Eşitleme sunucusuna ulaşılamadı', ['what' => $what, 'error' => $e->getMessage()]);
            throw new SyncHttpException('Sunucuya ulaşılamıyor. İnternet bağlantısı yok ya da sunucu yanıt vermiyor; değişiklikler bekletiliyor.', 0, 'offline', self::shortDetail($e->getMessage()));
        }
    }

    /** "cURL error 6: Could not resolve host: x (see https://curl.haxx.se/…) for https://…" → "cURL error 6: Could not resolve host: x" */
    public static function shortDetail(string $message): string
    {
        $m = preg_replace('~\s*\(see https?://[^)]*\)~i', '', $message) ?? $message;
        $m = preg_replace('~\s+for\s+https?://\S+\s*$~i', '', $m) ?? $m;

        return mb_substr(trim($m), 0, 200);
    }

    public function ping(): array
    {
        return $this->call(fn (PendingRequest $h) => $h->get('sync/ping'), 'Sağlık yoklaması');
    }

    public function status(int $pending): array
    {
        return $this->call(fn (PendingRequest $h) => $h->get('sync/status', ['pending' => $pending]), 'Durum');
    }

    public function pull(int $cursor, int $limit): array
    {
        return $this->call(fn (PendingRequest $h) => $h->get('sync/pull', ['cursor' => $cursor, 'limit' => $limit]), 'Çekme');
    }

    public function push(array $payload): array
    {
        return $this->call(fn (PendingRequest $h) => $h->timeout(120)->post('sync/push', $payload), 'Gönderme');
    }

    public function manifest(): array
    {
        return $this->call(fn (PendingRequest $h) => $h->get('sync/snapshot'), 'Anlık görüntü listesi');
    }

    public function snapshotPage(string $table, int $after, int $limit): array
    {
        return $this->call(fn (PendingRequest $h) => $h->timeout(120)->get('sync/snapshot/'.$table, ['after' => $after, 'limit' => $limit]), 'Anlık görüntü');
    }

    public function rows(string $table, array $uuids): array
    {
        return $this->call(fn (PendingRequest $h) => $h->post('sync/rows', ['table' => $table, 'uuids' => array_values($uuids)]), 'Satır getirme');
    }

    public function numberBlock(string $name, int $size): array
    {
        return $this->call(fn (PendingRequest $h) => $h->post('sync/number-blocks', ['name' => $name, 'size' => $size]), 'Numara bloğu');
    }

    public function keyBundle(): array
    {
        return $this->call(fn (PendingRequest $h) => $h->post('sync/key-bundle'), 'Anahtar paketi');
    }

    public function fileManifest(?string $since, int $limit): array
    {
        return $this->call(fn (PendingRequest $h) => $h->get('sync/files/manifest', array_filter(['since' => $since, 'limit' => $limit])), 'Dosya listesi');
    }

    /** İçerik adresli indirme: yanıt doğrudan geçici dosyaya yazılır. */
    public function downloadFile(string $sha256, string $target): void
    {
        try {
            $r = $this->http()->timeout(300)->sink($target)->get('sync/files/'.$sha256);
        } catch (ConnectionException $e) {
            @unlink($target);
            \Illuminate\Support\Facades\Log::warning('Eşitleme sunucusuna ulaşılamadı', ['what' => 'Dosya indirme', 'error' => $e->getMessage()]);
            throw new SyncHttpException('Sunucuya ulaşılamıyor; dosya indirmesi bekletiliyor.', 0, 'offline', self::shortDetail($e->getMessage()));
        }
        if ($r->failed()) {
            // hata gövdesi de hedefe yazılmış olabilir: JSON mesajını oradan oku
            $body = @file_get_contents($target, false, null, 0, 4096) ?: '';
            @unlink($target);
            $json = json_decode($body, true) ?: [];
            throw new SyncHttpException((string) ($json['message'] ?? "Dosya indirilemedi (HTTP {$r->status()})"), $r->status(), (string) ($json['error_code'] ?? ''));
        }
    }

    public function uploadFile(string $disk, string $path, string $fullPath, string $sha256): array
    {
        return $this->call(fn (PendingRequest $h) => $h->timeout(300)
            ->attach('file', fopen($fullPath, 'r'), basename($path))
            ->post('sync/files', ['disk' => $disk, 'path' => $path, 'sha256' => $sha256]), 'Dosya yükleme');
    }

    /** Uzaktan tanı paketi: terminal teşhis verisini (sıkıştırılmış) sunucuya cihaz jetonuyla yükler. */
    public function uploadDiag(string $fullPath, array $summary, string $label, string $version): array
    {
        return $this->call(fn (PendingRequest $h) => $h->timeout(120)
            ->attach('blob', fopen($fullPath, 'r'), 'terminal-diag.tar.gz')
            ->post('sync/terminal-diag', ['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'device_label' => $label, 'app_version' => $version]), 'Tanı paketi');
    }

        /** Biyometrik terminallerin bu kurulumdaki son durumu (web ekranı "Son durum: … üzerinden"). */
    public function terminalStatus(array $devices): array
    {
        return $this->call(fn (PendingRequest $h) => $h->timeout(15)->post('sync/terminal-status', ['devices' => $devices]), 'Terminal durumu');
    }

    /** Masaüstünde okunan (sunucudan inmiş) bildirimler → sunucuda da okundu. */
    public function notificationReads(array $reads): array
    {
        return $this->call(fn (PendingRequest $h) => $h->timeout(15)->post('sync/notification-reads', ['reads' => $reads]), 'Bildirim okundu bilgisi');
    }

    /** Çevrimiçi parola değişikliği (kuyruğa alınmaz). */
    public function changePassword(string $userUuid, string $current, string $new): array
    {
        return $this->call(fn (PendingRequest $h) => $h->post('sync/password', [
            'user' => $userUuid, 'current_password' => $current, 'password' => $new,
        ]), 'Parola değişikliği');
    }

    /** Eşleştirme (oturumsuz). */
    public static function pair(string $serverUrl, array $payload): array
    {
        Http::allowStrayRequests([rtrim($serverUrl, '/').'/*']);
        try {
            $r = Http::baseUrl(rtrim($serverUrl, '/').'/api/v1/')->acceptJson()->timeout(30)->post('sync/pair', $payload);
        } catch (ConnectionException $e) {
            throw new SyncHttpException('Sunucuya ulaşılamıyor ('.$e->getMessage().').', 0, 'offline');
        }
        if ($r->failed()) {
            $msg = $r->json('message') ?: 'Eşleştirme başarısız';
            if ($errors = $r->json('errors')) {
                $msg .= ' '.collect($errors)->flatten()->implode(' ');
            }
            throw new SyncHttpException((string) $msg, $r->status(), (string) ($r->json('error_code') ?? ''));
        }

        return (array) $r->json();
    }
}
