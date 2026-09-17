<?php

namespace App\Services\Messaging\Providers;

use App\Models\OutboundMessage;
use App\Services\Campaigns\EmailRenderer;
use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\TestResult;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * E-posta sağlayıcısı: entegrasyon config'inden (kuruma özel, şifreli) SMTP ayarlarını okuyup `mail` config'ini
 * o istek için geçici olarak değiştirir (uygulama genelindeki .env SMTP'sine dokunmaz).
 * config: host, port, username, password, encryption (tls|ssl|none), from_address, from_name, reply_to
 *
 * Gövde kurum logolu HTML şablonla (düz metin alternatifiyle) gider; toplu gönderimde
 * List-Unsubscribe (tek tık) başlıkları eklenir.
 * GÜVENLİK: SMTP istisna metni parola/sunucu yanıtı içerebilir — kullanıcıya yalnız güvenli özet döner.
 */
class EmailProvider implements MessageProvider
{
    public function send(OutboundMessage $message, array $config): ProviderResult
    {
        if (empty($config['host']) || empty($config['from_address'])) {
            return ProviderResult::fail('E-posta yapılandırması eksik: SMTP sunucusu veya gönderen adresi yok.');
        }

        $this->applyConfig($config);
        $rendered = EmailRenderer::render($message);
        $subject = $message->subject ?: ($config['from_name'] ?? 'Erbaa Bilgi Eğitim');

        try {
            Mail::mailer('smtp')->send([], [], function (Message $m) use ($message, $config, $rendered, $subject) {
                $m->to($message->to)->subject($subject);
                $m->from($config['from_address'], $config['from_name'] ?? 'Erbaa Bilgi Eğitim');
                if (! empty($config['reply_to'])) {
                    $m->replyTo($config['reply_to']);
                }
                $m->html($rendered['html']);
                $m->text($rendered['text']);
                if ($rendered['unsubscribe_url']) {
                    $headers = $m->getSymfonyMessage()->getHeaders();
                    $headers->addTextHeader('List-Unsubscribe', '<'.$rendered['unsubscribe_url'].'>');
                    $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }
            });
        } catch (\Throwable $e) {
            return ProviderResult::fail(self::safeError($e));
        }

        return ProviderResult::ok();
    }

    public function testConnection(array $config): TestResult
    {
        if (empty($config['host']) || empty($config['from_address'])) {
            return TestResult::fail('SMTP sunucusu ve gönderen adresi girilmeli.');
        }

        $this->applyConfig($config);

        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $transport->start();
            if (method_exists($transport, 'stop')) {
                $transport->stop();
            }
        } catch (\Throwable $e) {
            return TestResult::fail('SMTP sunucusuna bağlanılamadı: '.self::safeError($e));
        }

        return TestResult::ok('SMTP bağlantısı kuruldu: '.$config['host'].':'.($config['port'] ?? 587));
    }

    /** "Test e-postası gönder": yalnız kullanıcı adres girip düğmeye bastığında çalışır. */
    public function sendTest(array $config, string $to, string $institutionName): TestResult
    {
        if (empty($config['host']) || empty($config['from_address'])) {
            return TestResult::fail('SMTP sunucusu ve gönderen adresi girilmeli.');
        }

        $message = new OutboundMessage([
            'channel' => 'email', 'to' => $to,
            'subject' => 'Test e-postası — '.$institutionName,
            'body' => "Merhaba,\n\nBu, {$institutionName} yönetim sistemindeki e-posta (SMTP) ayarlarını doğrulamak için gönderilen bir test iletisidir.\n\nBu e-postayı gördüyseniz ayarlar doğru çalışıyor.",
        ]);
        $message->branch_id = app(\App\Support\BranchContext::class)->id();

        $result = $this->send($message, $config);

        return $result->success
            ? TestResult::ok("Test e-postası {$to} adresine gönderildi. Gelen kutusunu (ve gereksiz klasörünü) kontrol edin.")
            : TestResult::fail($result->error ?? 'Test e-postası gönderilemedi.');
    }

    private function applyConfig(array $config): void
    {
        $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));
        $port = (int) ($config['port'] ?? ($encryption === 'ssl' ? 465 : 587));

        config([
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $config['host'],
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => $config['username'] ?? null,
            'mail.mailers.smtp.password' => $config['password'] ?? null,
            'mail.mailers.smtp.encryption' => $encryption === 'none' ? null : $encryption,
            // Laravel 11+: ssl = smtps şeması; tls = smtp + STARTTLS
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.timeout' => 20,
            'mail.from.address' => $config['from_address'],
            'mail.from.name' => $config['from_name'] ?? 'Erbaa Bilgi Eğitim',
        ]);

        // Önceki ayarla oluşturulmuş mailer örneği önbellekte kalmasın
        app('mail.manager')->purge('smtp');
    }

    private static function safeError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        return match (true) {
            str_contains($msg, '535') || stripos($msg, 'authentication') !== false || stripos($msg, 'credentials') !== false => 'SMTP kimlik doğrulaması başarısız (kullanıcı adı/parola).',
            stripos($msg, 'Connection could not be established') !== false || stripos($msg, 'timed out') !== false || stripos($msg, 'Connection refused') !== false => 'SMTP sunucusuna bağlantı kurulamadı (sunucu/port/şifreleme ayarını kontrol edin).',
            stripos($msg, 'certificate') !== false || stripos($msg, 'SSL') !== false || stripos($msg, 'TLS') !== false => 'SMTP güvenli bağlantı (SSL/TLS) kurulamadı.',
            str_contains($msg, '550') || str_contains($msg, '553') || str_contains($msg, '554') => 'SMTP sunucusu alıcıyı ya da gönderen adresini reddetti.',
            str_contains($msg, '421') || str_contains($msg, '451') || str_contains($msg, '452') => 'SMTP sunucusu geçici olarak reddetti (gönderim sınırı olabilir); yeniden denenecek.',
            default => 'E-posta gönderilemedi (SMTP hatası).',
        };
    }
}
