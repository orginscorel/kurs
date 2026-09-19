<?php

namespace App\Services\Devices\Drivers\Yt33\Push;

use App\Services\Devices\Terminal\PushListener;

/**
 * Perkotek YT33 (FK ailesi) GERÇEK ZAMANLI PUSH çerçevesi — 19.09.2026'da cihazın kendi trafiğiyle doğrulandı
 * (dev_model R6, `nc -lk 7005 | xxd`):
 *
 *   POST / HTTP/1.0
 *   request_code: realtime_glog | realtime_enroll_data
 *   trans_id: RTLogSend | RTEnrollData
 *   dev_id: B6D30DD3A1580074
 *   dev_model: R6
 *   token: …
 *   Content-Length: N
 *
 *   {"ioMode":10,"time":"20260919103000","userId":"986","verifyMode":"Fp"}                       (glog)
 *   {"card":"","fps":["<base64 şablon>"],"name":"…","privilege":0,"userId":"986","vaildStart":…}  (enroll)
 *
 * Onay verilmezse cihaz aynı isteği tekrar gönderir. Onay (FK web sunucusu biçimi): gövdesiz 200 +
 * `response_code: OK` + isteğin `trans_id`'si. Gövde JSON'dan sonra "\0" + ikili ek içerebilir (FK "BIN_n" blokları).
 *
 * KVKK: parmak izi / yüz / avuç şablonu ASLA saklanmaz — redact() ham kayda yazılmadan önce siler.
 */
final class RealtimeProtocol
{
    public const GLOG = 'realtime_glog';

    public const ENROLL = 'realtime_enroll_data';

    /** Biyometrik şablon taşıyan JSON anahtarları (küçük harf). */
    private const TEMPLATE_KEYS = 'fps|fp|fp_data|fingerprint|fingerprints|face|faces|face_data|facedata|palm|palms|palm_data|photo|photos|enroll_data';

    /**
     * Tamamlanmış bir HTTP isteğini çözer. FK push isteği değilse null.
     *
     * @return array{request_code:string, trans_id:string, dev_id:string, dev_model:string, data:array<string,mixed>|null, json_error:?string}|null
     */
    public static function parse(string $request): ?array
    {
        $http = PushListener::parseHttp($request);

        if ($http === null || $http['method'] === 'YANIT') {
            return null;
        }

        $headers = self::headers($http['headers']);
        $code = strtolower(trim($headers['request_code'] ?? ''));

        if ($code === '') {
            return null;
        }

        $body = $http['body'];
        $nul = strpos($body, "\0");
        $json = trim($nul === false ? $body : substr($body, 0, $nul));
        $data = null;
        $error = null;

        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                $error = json_last_error_msg();
            }
        }

        return [
            'request_code' => $code,
            'trans_id' => trim($headers['trans_id'] ?? ''),
            'dev_id' => trim($headers['dev_id'] ?? ''),
            'dev_model' => trim($headers['dev_model'] ?? ''),
            'data' => $data,
            'json_error' => $error,
        ];
    }

    /** Cihazın beklediği onay: gövdesiz 200 + response_code + aynı trans_id (tekrar göndermeyi durdurur). */
    public static function ack(string $transId, string $responseCode = 'OK'): string
    {
        $transId = preg_replace('/[^\x21-\x7E]/', '', $transId) ?? '';

        return "HTTP/1.0 200 OK\r\n"
            ."response_code: {$responseCode}\r\n"
            .($transId !== '' ? "trans_id: {$transId}\r\n" : '')
            ."Content-Type: application/octet-stream\r\n"
            ."Content-Length: 0\r\n"
            ."Connection: close\r\n\r\n";
    }

    /** İstek koduna göre onay yanıtı; komut yoklamasına ("receive_cmd") bekleyen komut olmadığı söylenir. */
    public static function replyFor(array $parsed): string
    {
        return $parsed['request_code'] === 'receive_cmd'
            ? self::ack($parsed['trans_id'], 'ERROR_NO_CMD')
            : self::ack($parsed['trans_id']);
    }

    /**
     * Biyometrik şablonları baytlardan siler (ham kayıt, log, tanı paketi hiçbirinde şablon kalmaz).
     * JSON'daki şablon alanları "[gizlendi]" olur; "\0" sonrası ikili ek (FK BIN blokları) şablon içeren bir
     * istekte tümüyle atılır. Dönen not kayda yazılır.
     *
     * @return array{0:string, 1:?string} [gizlenmiş baytlar, not]
     */
    public static function redact(string $bytes): array
    {
        if ($bytes === '' || ! preg_match('/"(?:'.self::TEMPLATE_KEYS.')"\s*:|request_code:\s*realtime_enroll/i', $bytes)) {
            return [$bytes, null];
        }

        $before = strlen($bytes);
        $keys = self::TEMPLATE_KEYS;

        // Tam dizi / metin değerler
        $out = preg_replace('/"('.$keys.')"(\s*):(\s*)(\[[^\]]*\]|"(?:[^"\\\\]|\\\\.)*")/i', '"$1"$2:$3"[gizlendi]"', $bytes) ?? $bytes;
        // 1 MB sınırında kesilmiş parça: kapanmamış dizi / metin sona kadar
        $out = preg_replace('/"('.$keys.')"(\s*):(\s*)(\[[^\]]*|"[^"]*)$/i', '"$1"$2:$3"[gizlendi]"', $out) ?? $out;

        // Şablon taşıyan istekte JSON'dan sonraki ikili ek (FK BIN blokları)
        $bodyAt = strpos($out, "\r\n\r\n");   // yoksa bayt dizisi yalnız gövdedir
        $nul = strpos($out, "\0", $bodyAt === false ? 0 : $bodyAt + 4);
        if ($nul !== false) {
            $out = substr($out, 0, $nul);
        }

        $removed = $before - strlen($out);

        return [$out, $removed > 0 ? "Biyometrik şablon gizlendi ({$removed} bayt silindi, KVKK)." : null];
    }

    /** Cihaz saati "YYYYMMDDHHMMSS" (cihazın yerel saati) → uygulama saat dilimi. */
    public static function time(mixed $value): ?\Carbon\CarbonImmutable
    {
        $value = preg_replace('/\D/', '', (string) $value) ?? '';

        if (strlen($value) !== 14) {
            return null;
        }

        $at = \Carbon\CarbonImmutable::createFromFormat('YmdHis', $value, config('app.timezone'));

        return $at && $at->format('YmdHis') === $value ? $at : null;
    }

    /** @return array<string,string> küçük harf başlık adı → değer */
    private static function headers(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (($p = strpos($line, ':')) !== false) {
                $out[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
        }

        return $out;
    }
}
