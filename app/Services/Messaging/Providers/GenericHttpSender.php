<?php

namespace App\Services\Messaging\Providers;

use App\Models\OutboundMessage;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\TestResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Yapılandırılabilir genel HTTP gönderici: WhatsApp (genel) ve SMS sağlayıcıları bunu paylaşır.
 * URL/başlık/gövde şablonunda {{to}} ve {{body}} yer tutucuları değiştirilir.
 */
class GenericHttpSender
{
    public static function send(OutboundMessage $message, array $config, string $label): ProviderResult
    {
        $url = $config['url'] ?? null;
        if (! $url) {
            return ProviderResult::fail("{$label} sağlayıcı adresi (URL) yapılandırılmamış.");
        }

        $vars = ['to' => $message->to, 'body' => $message->body];
        $method = strtoupper($config['method'] ?? 'POST');

        try {
            $request = Http::timeout(15)->withHeaders(self::fill($config['headers'] ?? [], $vars));

            if (! empty($config['query_template'])) {
                $request = $request->withOptions(['query' => self::fill($config['query_template'], $vars)]);
            }

            $body = null;
            if (! empty($config['body_template'])) {
                $decoded = json_decode(self::fillString($config['body_template'], $vars), true);
                $body = $decoded ?? self::fillString($config['body_template'], $vars);
            }

            $response = is_array($body)
                ? $request->send($method, $url, ['json' => $body])
                : ($body !== null ? $request->send($method, $url, ['body' => $body]) : $request->send($method, $url));
        } catch (\Throwable $e) {
            return ProviderResult::fail("{$label} sağlayıcısına ulaşılamadı: ".$e->getMessage());
        }

        if ($response->failed()) {
            return ProviderResult::fail("{$label} gönderimi reddedildi (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 300));
        }

        $successField = $config['success_path'] ?? null;
        if ($successField && ! Arr::get($response->json() ?? [], $successField)) {
            return ProviderResult::fail("{$label} sağlayıcısı başarısız yanıt döndürdü: ".mb_substr($response->body(), 0, 300));
        }

        $idField = $config['message_id_path'] ?? null;
        $providerId = $idField ? (string) Arr::get($response->json() ?? [], $idField) : null;

        return ProviderResult::ok($providerId ?: null);
    }

    public static function test(array $config, string $label): TestResult
    {
        $url = $config['test_url'] ?? $config['url'] ?? null;
        if (! $url) {
            return TestResult::fail('Adres (URL) girilmedi.');
        }

        try {
            $response = Http::timeout(10)->withHeaders($config['headers'] ?? [])->get($url);
        } catch (\Throwable $e) {
            return TestResult::fail("{$label} adresine ulaşılamadı: ".$e->getMessage());
        }

        if ($response->failed() && $response->status() >= 500) {
            return TestResult::fail("{$label} sunucusu hata döndürdü (HTTP {$response->status()}).");
        }

        return TestResult::ok("Adrese ulaşıldı (HTTP {$response->status()}). Gerçek gönderim testi için deneme mesajı kullanın.");
    }

    private static function fill(array $template, array $vars): array
    {
        return array_map(fn ($v) => is_string($v) ? self::fillString($v, $vars) : $v, $template);
    }

    private static function fillString(string $template, array $vars): string
    {
        return strtr($template, ['{{to}}' => $vars['to'], '{{body}}' => $vars['body']]);
    }
}
