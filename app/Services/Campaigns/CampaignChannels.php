<?php

namespace App\Services\Campaigns;

use App\Models\Integration;
use App\Services\Messaging\ProviderFactory;
use App\Support\BranchContext;

/**
 * Toplu gönderim kanallarının (SMS / e-posta) bağlantı durumu ve ayarları.
 * "Bağlı" = entegrasyon etkin + son bağlantı testi başarılı (WhatsApp'la aynı kural).
 */
final class CampaignChannels
{
    public const DEFAULT_RATE = ['sms' => 300, 'email' => 30];

    public static function integration(string $kind, ?int $branchId = null): ?Integration
    {
        $branchId ??= app(BranchContext::class)->id();

        return Integration::query()->where('kind', $kind)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->first();
    }

    public static function connected(?Integration $integration): bool
    {
        return (bool) ($integration?->is_enabled) && $integration?->status === 'connected';
    }

    /** SMS kanalı toplu gönderime uygun mu (bağlı + toplu sürücü + başlık seçili)? @return array{ok:bool, reason:?string} */
    public static function smsReady(?Integration $integration): array
    {
        if (! self::connected($integration)) {
            return ['ok' => false, 'reason' => 'SMS sağlayıcısı bağlı değil. Ayarlar › Mesaj kanalları ekranından sağlayıcıyı kaydedip bağlantıyı test edin.'];
        }
        if (! isset(ProviderFactory::SMS_GATEWAYS[$integration->provider])) {
            return ['ok' => false, 'reason' => 'Toplu SMS için NetGSM, Mutlucell, VatanSMS ya da Simülasyon sağlayıcısı seçilmeli.'];
        }
        if ($integration->provider !== 'simulation' && empty($integration->config()['header'])) {
            return ['ok' => false, 'reason' => 'SMS başlığı (gönderici adı) seçilmemiş.'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /** @return array{ok:bool, reason:?string} */
    public static function emailReady(?Integration $integration): array
    {
        if (! self::connected($integration)) {
            return ['ok' => false, 'reason' => 'E-posta (SMTP) bağlı değil. Ayarlar › Mesaj kanalları ekranından SMTP bilgilerini kaydedip bağlantıyı test edin.'];
        }

        return ['ok' => true, 'reason' => null];
    }

    public static function rate(string $channel, array $config): int
    {
        $rate = (int) ($config['rate_per_minute'] ?? 0);

        return max(1, min($channel === 'sms' ? 5000 : 600, $rate ?: self::DEFAULT_RATE[$channel]));
    }
}
