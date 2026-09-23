<?php

namespace App\Services\Messaging\Whatsapp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * whatsapp-web.js REST köprüsüne (chrishubert/whatsapp-api uyumlu) konuşan ince istemci.
 * Sözleşme bogahost `wa_connect` modülünden doğrulandı:
 *   - Kimlik: `Authorization: Bearer <token>` + `X-API-Token: <token>`
 *   - GET  /session/status/{sid}          → { success, state:"CONNECTED"|…, message:"session_connected", id? }
 *   - GET  /session/qr/{sid}              → { qr | qrDataUrl | result }
 *   - GET  /session/qr/{sid}/image        → PNG (ikili)
 *   - GET  /session/start/{sid}           → oturumu başlatır (QR üretir)
 *   - GET  /session/terminate/{sid}       → oturumu kapatır
 *   - POST /client/sendMessage/{sid}      → body { chatId:"<hane>@c.us", contentType:"string", content }
 *
 * Yapılandırma tümüyle `Integration->config()` (şifreli) içinden gelir; kodda sır/adres yoktur.
 */
class WwebjsClient
{
    private string $base;

    private string $token;

    private string $sessionId;

    public function __construct(array $config)
    {
        $this->base = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        $this->token = trim((string) ($config['api_key'] ?? ''));
        $sid = trim((string) ($config['session_id'] ?? 'kurs'));
        $this->sessionId = $sid !== '' ? $sid : 'kurs';
    }

    public function isConfigured(): bool
    {
        return $this->base !== '';
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /** Oturum durumu. @return array{connected:bool,state:string,phone:string,ok:bool,error:?string} */
    public function status(): array
    {
        $res = $this->request('GET', "/session/status/{$this->sessionId}", null, 10);
        if (! $res['ok']) {
            return ['connected' => false, 'state' => 'unknown', 'phone' => '', 'ok' => false, 'error' => $res['error']];
        }

        $data = $res['json'] ?? [];
        $state = strtoupper((string) ($data['state'] ?? 'unknown'));
        $connected = $state === 'CONNECTED' || ($data['message'] ?? '') === 'session_connected';

        return [
            'connected' => $connected,
            'state' => $state,
            'phone' => (string) ($data['id'] ?? $data['phone'] ?? ''),
            'ok' => true,
            'error' => null,
        ];
    }

    /** Oturumu başlatır (QR üretilmesi için). */
    public function start(): array
    {
        return $this->request('GET', "/session/start/{$this->sessionId}", null, 15);
    }

    /** Oturumu kapatır. */
    public function terminate(): array
    {
        return $this->request('GET', "/session/terminate/{$this->sessionId}", null, 15);
    }

    /** Ham QR verisi (dataURL veya metin). @return array{ok:bool,qr:?string,error:?string} */
    public function qr(): array
    {
        $res = $this->request('GET', "/session/qr/{$this->sessionId}", null, 10);
        if (! $res['ok']) {
            return ['ok' => false, 'qr' => null, 'error' => $res['error']];
        }
        $data = $res['json'] ?? [];
        $qr = $data['qr'] ?? $data['qrDataUrl'] ?? $data['result'] ?? null;

        return ['ok' => $qr !== null, 'qr' => $qr ? (string) $qr : null, 'error' => null];
    }

    /**
     * QR PNG'sini bottan ikili olarak çeker (Laravel bunu tarayıcıya proxy'ler; https→http mixed-content olmaz).
     * @return array{ok:bool,bytes:?string,content_type:string,error:?string}
     */
    public function qrImage(): array
    {
        try {
            $response = $this->http(20)->get($this->url("/session/qr/{$this->sessionId}/image"));
        } catch (\Throwable $e) {
            return ['ok' => false, 'bytes' => null, 'content_type' => 'image/png', 'error' => $e->getMessage()];
        }
        if ($response->failed()) {
            return ['ok' => false, 'bytes' => null, 'content_type' => 'image/png', 'error' => 'HTTP '.$response->status()];
        }

        return [
            'ok' => true,
            'bytes' => $response->body(),
            'content_type' => $response->header('Content-Type') ?: 'image/png',
            'error' => null,
        ];
    }

    /** Tek mesaj gönderir. @return array{ok:bool,id:?string,error:?string} */
    public function sendMessage(string $to, string $content, ?string $mediaUrl = null): array
    {
        $chatId = $this->chatId($to);
        if ($chatId === '') {
            return ['ok' => false, 'id' => null, 'error' => 'Geçersiz alıcı numarası.'];
        }

        $payload = ['chatId' => $chatId, 'contentType' => 'string', 'content' => $content];
        if ($mediaUrl) {
            $payload = ['chatId' => $chatId, 'contentType' => 'MessageMediaFromURL', 'content' => $mediaUrl];
            if ($content !== '') {
                $payload['options'] = ['caption' => $content];
            }
        }

        $res = $this->request('POST', "/client/sendMessage/{$this->sessionId}", $payload, 20);
        if (! $res['ok']) {
            return ['ok' => false, 'id' => null, 'error' => $res['error'] ?? 'Gönderim başarısız.'];
        }
        $json = $res['json'] ?? [];
        $id = $json['messageId'] ?? ($json['message']['id']['id'] ?? ($json['id'] ?? null));

        return ['ok' => true, 'id' => $id ? (string) $id : null, 'error' => null];
    }

    /** 5xxxxxxxxx / 05xx / +90 → 90xxxxxxxxxx (bogahost normalize mantığı). */
    public function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if (stripos($phone, '@g.us') !== false || stripos($phone, '@s.whatsapp.net') !== false || stripos($phone, '@c.us') !== false) {
            return $phone;
        }
        $cleaned = ltrim(preg_replace('/[^0-9+]/', '', $phone) ?? '', '+');
        $cleaned = ltrim($cleaned, '0');
        if (preg_match('/^5\d{9}$/', $cleaned)) {
            $cleaned = '90'.$cleaned;
        }

        return $cleaned;
    }

    /** Bireysel sohbet için chatId (<hane>@c.us); grup ID'leri korunur. */
    public function chatId(string $to): string
    {
        $to = trim($to);
        if (stripos($to, '@g.us') !== false || stripos($to, '@s.whatsapp.net') !== false || stripos($to, '@c.us') !== false) {
            return $to;
        }
        $digits = preg_replace('/[^0-9]/', '', $this->normalizePhone($to)) ?? '';

        return strlen($digits) >= 10 ? $digits.'@c.us' : '';
    }

    /** @return array{ok:bool,status:int,json:?array,body:string,error:?string} */
    private function request(string $method, string $path, ?array $payload, int $timeout): array
    {
        try {
            $req = $this->http($timeout);
            $response = strtoupper($method) === 'POST'
                ? $req->post($this->url($path), $payload ?? [])
                : $req->get($this->url($path));
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'error' => 'Bota ulaşılamadı: '.$e->getMessage()];
        }

        $body = $response->body();
        $json = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
            $json = is_array($decoded) ? $decoded : null;
        }

        if ($response->failed()) {
            return ['ok' => false, 'status' => $response->status(), 'json' => $json, 'body' => $body, 'error' => 'HTTP '.$response->status()];
        }

        return ['ok' => true, 'status' => $response->status(), 'json' => $json, 'body' => $body, 'error' => null];
    }

    private function http(int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->token,
            'X-API-Token' => $this->token,
        ])->withOptions(['verify' => false])->connectTimeout(5)->timeout($timeout);
    }

    private function url(string $path): string
    {
        return $this->base.'/'.ltrim($path, '/');
    }
}
