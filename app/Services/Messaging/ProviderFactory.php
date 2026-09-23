<?php

namespace App\Services\Messaging;

use App\Models\Integration;
use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\Providers\EmailProvider;
use App\Services\Messaging\Providers\EmailSimulationProvider;
use App\Services\Messaging\Providers\SmsGenericHttpProvider;
use App\Services\Messaging\Providers\WhatsAppGenericHttpProvider;
use App\Services\Messaging\Providers\WhatsAppMetaCloudProvider;
use App\Services\Messaging\Providers\WhatsAppWwebjsProvider;
use App\Services\Messaging\Sms\Drivers\MutlucellGateway;
use App\Services\Messaging\Sms\Drivers\NetGsmGateway;
use App\Services\Messaging\Sms\Drivers\SimulationGateway;
use App\Services\Messaging\Sms\Drivers\VatanSmsGateway;

/**
 * Kanal → sağlayıcı eşleşmesi. `Integration.kind` = whatsapp|sms|email, `Integration.provider`
 * gerçek uygulamayı seçer (ör. whatsapp → meta_cloud | generic_http; sms → netgsm | mutlucell | vatansms | simulation).
 */
class ProviderFactory
{
    /** SMS sürücüleri: provider kodu => sınıf */
    public const SMS_GATEWAYS = [
        'netgsm' => NetGsmGateway::class,
        'mutlucell' => MutlucellGateway::class,
        'vatansms' => VatanSmsGateway::class,
        'simulation' => SimulationGateway::class,
    ];

    /** @return array{provider: MessageProvider, integration: ?Integration}|null */
    public function resolve(string $channel): ?array
    {
        $kind = match ($channel) {
            'whatsapp' => 'whatsapp',
            'sms' => 'sms',
            'email' => 'email',
            default => null,
        };

        if (! $kind) {
            return null;
        }

        $integration = Integration::query()->where('kind', $kind)
            ->when(app(\App\Support\BranchContext::class)->id(), fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->first();
        $provider = $this->make($kind, $integration?->provider);

        return ['provider' => $provider, 'integration' => $integration];
    }

    public function make(string $kind, ?string $providerName): MessageProvider
    {
        return match (true) {
            $kind === 'whatsapp' && $providerName === 'meta_cloud' => new WhatsAppMetaCloudProvider,
            $kind === 'whatsapp' && $providerName === 'wwebjs' => new WhatsAppWwebjsProvider,
            $kind === 'whatsapp' => new WhatsAppGenericHttpProvider,
            $kind === 'sms' && isset(self::SMS_GATEWAYS[$providerName ?? '']) => app(self::SMS_GATEWAYS[$providerName]),
            $kind === 'sms' => new SmsGenericHttpProvider,
            $kind === 'email' && $providerName === 'simulation' => new EmailSimulationProvider,
            $kind === 'email' => new EmailProvider,
            default => new WhatsAppGenericHttpProvider,
        };
    }

    /** Toplu gönderim yapabilen SMS sürücüsü (genel HTTP sağlayıcısı değildir). */
    public function smsGateway(?string $providerName): ?SmsGateway
    {
        $class = self::SMS_GATEWAYS[$providerName ?? ''] ?? null;

        return $class ? app($class) : null;
    }
}
