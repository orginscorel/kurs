<?php

namespace App\Services\Push;

use App\Models\PushToken;
use App\Services\Messaging\ProviderResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Expo Push API — anahtar/entegrasyon gerektirmez, yalnızca kayıtlı Expo push token'ları
 * gerekir. Kayıtlı cihazı yoksa sessizce atlanır (hata sayılmaz).
 */
class ExpoPushService
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function sendToUser(int $userId, string $body, ?string $title = null, ?string $url = null): ProviderResult
    {
        $tokens = PushToken::query()->where('user_id', $userId)->pluck('token');

        if ($tokens->isEmpty()) {
            return ProviderResult::ok(); // kayıtlı cihaz yok — hata değil, atlanır
        }

        return $this->sendToTokens($tokens->all(), $body, $title, $url);
    }

    /** @param list<string> $tokens */
    public function sendToTokens(array $tokens, string $body, ?string $title = null, ?string $url = null): ProviderResult
    {
        if (empty($tokens)) {
            return ProviderResult::ok();
        }

        $messages = array_map(fn ($token) => [
            'to' => $token, 'title' => $title ?? 'Erbaa Bilgi Eğitim', 'body' => $body,
            'data' => $url ? ['url' => $url] : [], 'sound' => 'default',
        ], $tokens);

        try {
            $response = Http::timeout(10)->post(self::ENDPOINT, $messages);
        } catch (\Throwable $e) {
            Log::warning('Expo push gönderilemedi', ['error' => $e->getMessage()]);

            return ProviderResult::fail('Expo push servisine ulaşılamadı.');
        }

        if ($response->failed()) {
            return ProviderResult::fail('Expo push reddedildi (HTTP '.$response->status().').');
        }

        return ProviderResult::ok();
    }
}
