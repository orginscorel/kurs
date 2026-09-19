<?php

namespace App\Services\Devices\Analysis;

use App\Exceptions\BusinessRuleException;

/**
 * Tarayıcının "Save all as HAR" dışa aktarımını (cihaz web paneli trafiği) protokol örneklerine çevirir.
 *
 * GİZLİLİK: Cookie / Set-Cookie / Authorization başlıkları ve gövde/sorgudaki parola benzeri alanlar
 * (password, pwd, pass, passwd, pw, sifre) '***' olarak MASKELENİR; ham parola hiçbir tabloya yazılmaz.
 * Uç noktalar UYDURULMAZ — yalnız dosyada gerçekten olan istekler alınır.
 */
class HarImporter
{
    private const SECRET_HEADERS = ['cookie', 'set-cookie', 'authorization', 'proxy-authorization'];

    private const SECRET_FIELDS = '(password|passwd|pass|pwd|pw|sifre|şifre|secret|token)';

    /** @return list<array{name:string, tx_hex:string, rx_hex:string, note:string, source:string}> */
    public function parse(string $json, ?string $onlyHost = null, int $max = 300): array
    {
        $har = json_decode($json, true);

        if (! is_array($har) || ! isset($har['log']['entries']) || ! is_array($har['log']['entries'])) {
            throw new BusinessRuleException('Dosya geçerli bir HAR değil (log.entries bulunamadı).', 'har_invalid');
        }

        $rows = [];

        foreach ($har['log']['entries'] as $entry) {
            $req = $entry['request'] ?? [];
            $res = $entry['response'] ?? [];
            $url = (string) ($req['url'] ?? '');
            $parts = parse_url($url) ?: [];

            if ($onlyHost && ($parts['host'] ?? '') !== $onlyHost) {
                continue;
            }
            if (preg_match('/\.(png|jpe?g|gif|ico|svg|woff2?|ttf|css)(\?|$)/i', $parts['path'] ?? '')) {
                continue;   // görsel/stil dosyaları protokol değildir
            }

            $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$this->maskQuery($parts['query']) : '');
            $method = strtoupper((string) ($req['method'] ?? 'GET'));
            $reqText = "{$method} {$path} ".($req['httpVersion'] ?? 'HTTP/1.1')."\r\n".$this->headers($req['headers'] ?? [])."\r\n".$this->maskBody((string) ($req['postData']['text'] ?? ''));
            $resText = 'HTTP '.($res['status'] ?? '?').' '.($res['statusText'] ?? '')."\r\n".$this->headers($res['headers'] ?? [])."\r\n".$this->responseBody($res['content'] ?? []);

            $rows[] = [
                'name' => mb_substr("{$method} ".($parts['path'] ?? '/'), 0, 120),
                'tx_hex' => bin2hex($reqText),
                'rx_hex' => bin2hex($resText),
                'note' => 'HAR: '.($parts['host'] ?? '?').' · '.($entry['startedDateTime'] ?? '').' · yanıt '.($res['status'] ?? '?').' · '.($res['content']['mimeType'] ?? ''),
                'source' => 'har',
            ];

            if (count($rows) >= $max) {
                break;
            }
        }

        return $rows;
    }

    private function headers(array $headers): string
    {
        $out = '';
        foreach ($headers as $h) {
            $name = (string) ($h['name'] ?? '');
            if ($name === '' || str_starts_with($name, ':')) {
                continue;
            }
            $value = in_array(strtolower($name), self::SECRET_HEADERS, true) ? '***' : (string) ($h['value'] ?? '');
            $out .= "{$name}: {$value}\r\n";
        }

        return $out;
    }

    private function maskQuery(string $query): string
    {
        return (string) preg_replace('/(^|&)([^=&]*'.self::SECRET_FIELDS.'[^=&]*)=[^&]*/i', '$1$2=***', $query);
    }

    public function maskBody(string $body): string
    {
        // form: a=b&password=x · JSON: "password":"x" · XML/diğer: password=x
        $body = (string) preg_replace('/("[^"]*'.self::SECRET_FIELDS.'[^"]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/iu', '$1"***"', $body);

        return (string) preg_replace('/(^|[&?\s])([^=&\s]*'.self::SECRET_FIELDS.'[^=&\s]*)=[^&\s]*/iu', '$1$2=***', $body);
    }

    private function responseBody(array $content): string
    {
        $text = (string) ($content['text'] ?? '');

        if (($content['encoding'] ?? null) === 'base64') {
            $text = (string) base64_decode($text);
        }

        return $this->maskBody(mb_substr($text, 0, 200000));
    }
}
