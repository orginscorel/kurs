<?php

namespace App\Services\Messaging\Sms\Drivers;

use App\Models\OutboundMessage;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\Sms\SmsLength;
use App\Services\Messaging\TestResult;

/**
 * Ortak davranış: tekli gönderim = tek elemanlı toplu gönderim, bağlantı testi = bakiye sorgusu.
 * GÜVENLİK: sağlayıcı hatası/istisna metni kullanıcıya olduğu gibi yazılmaz (URL'de kimlik bilgisi
 * olabilir) — yalnız kod + güvenli açıklama döner.
 */
abstract class AbstractSmsGateway implements SmsGateway
{
    public function send(OutboundMessage $message, array $config): ProviderResult
    {
        $results = $this->sendBatch([$message], $config, ['commercial' => (bool) ($message->is_commercial ?? false)]);

        return $results[$message->id] ?? ProviderResult::fail('SMS sağlayıcısı bu mesaj için sonuç döndürmedi.');
    }

    public function testConnection(array $config): TestResult
    {
        $missing = $this->missingConfig($config);
        if ($missing) {
            return TestResult::fail('Eksik bilgi: '.implode(', ', $missing).'.');
        }

        $balance = $this->balance($config);
        if (! $balance->success) {
            return TestResult::fail($balance->error ?? 'Bağlantı kurulamadı.');
        }

        return TestResult::ok($this->label().' bağlantısı başarılı: '.$balance->summary(), $balance->toArray());
    }

    public function maxBatchSize(): int
    {
        return 500;
    }

    /** @return list<string> eksik zorunlu alanların etiketleri */
    abstract protected function missingConfig(array $config): array;

    /** "905321112233" → "5321112233" (Türk sağlayıcılarının çoğu 10 haneli numara ister). */
    protected static function local10(string $to): string
    {
        $digits = preg_replace('/\D/', '', $to);
        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            return substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return substr($digits, 1);
        }

        return $digits;
    }

    /** Kodlama kipine göre gönderilecek metin ("ascii" kipinde Türkçe harfler dönüştürülür). */
    protected static function text(OutboundMessage $message, array $config): string
    {
        return SmsLength::analyze((string) $message->body, self::mode($config))['text'];
    }

    protected static function mode(array $config): string
    {
        $mode = $config['encoding'] ?? 'tr';

        return in_array($mode, SmsLength::MODES, true) ? $mode : 'tr';
    }

    /** Tüm mesajlara aynı sonucu uygular. @param list<OutboundMessage> $messages @return array<int, ProviderResult> */
    protected static function all(array $messages, ProviderResult $result): array
    {
        $out = [];
        foreach ($messages as $m) {
            $out[$m->id] = $result;
        }

        return $out;
    }

    protected static function connectionError(\Throwable $e, string $label): string
    {
        $kind = $e instanceof \Illuminate\Http\Client\ConnectionException ? 'bağlantı kurulamadı / zaman aşımı' : 'beklenmeyen yanıt';

        return "{$label} sunucusuna ulaşılamadı ({$kind}).";
    }

    /** İYS alıcı türü: BIREYSEL | TACIR */
    protected static function iysRecipientType(array $config): string
    {
        return strtoupper((string) ($config['iys_recipient_type'] ?? 'BIREYSEL')) === 'TACIR' ? 'TACIR' : 'BIREYSEL';
    }
}
