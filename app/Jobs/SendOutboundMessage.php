<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\OutboundMessage;
use App\Services\Messaging\ProviderFactory;
use App\Services\Push\ExpoPushService;
use App\Support\BranchContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Giden mesajı gerçekten gönderir. Üstel geri çekilme ile en fazla 3 deneme;
 * son denemede de başarısız olursa `failed` olarak işaretlenir (yönetici "yeniden dene" ile tetikler).
 */
class SendOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $messageId, public readonly int $branchId) {}

    /** @return array<int> saniye cinsinden geri çekilme */
    public function backoff(): array
    {
        return [60, 300, 900]; // 1 dk, 5 dk, 15 dk
    }

    public function handle(ProviderFactory $factory, ExpoPushService $push): void
    {
        app(BranchContext::class)->run($this->branchId, function () use ($factory, $push) {
            $message = OutboundMessage::query()->find($this->messageId);

            if (! $message || in_array($message->status, ['sent', 'delivered', 'read', 'cancelled'], true)) { // cancelled: öğrenci ayrıldı/donduruldu
                return;
            }

            $message->forceFill(['status' => 'sending', 'attempts' => $message->attempts + 1])->save();

            if ($message->channel === 'push') {
                $result = $push->sendToUser((int) $message->recipient_id, $message->body, $message->subject);
            } else {
                $resolved = $factory->resolve($message->channel);
                $integration = $resolved['integration'] ?? null;

                if (! $resolved || ! $integration || ! $integration->is_enabled || $integration->status !== 'connected') {
                    $message->forceFill([
                        'status' => 'failed',
                        'error' => $this->channelLabel($message->channel).' entegrasyonu bağlı değil.',
                    ])->save();

                    return;
                }

                $message->forceFill(['provider' => $integration->provider])->save();
                $result = $resolved['provider']->send($message, $integration->config());
            }

            if ($result->success) {
                $message->forceFill([
                    'status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $result->providerMessageId, 'error' => null,
                ])->save();

                return;
            }

            $isLastAttempt = $this->attempts() >= $this->tries;
            if ($isLastAttempt) {
                $message->forceFill(['status' => 'failed', 'error' => mb_substr($result->error ?? 'Bilinmeyen hata.', 0, 1000)])->save();

                return;
            }

            $message->forceFill(['status' => 'queued', 'error' => mb_substr($result->error ?? 'Bilinmeyen hata.', 0, 1000)])->save();
            throw new \RuntimeException($result->error ?? 'Mesaj gönderilemedi.');
        });
    }

    public function failed(?\Throwable $e): void
    {
        OutboundMessage::query()->whereKey($this->messageId)->update([
            'status' => 'failed',
            'error' => mb_substr('Gönderim kuyruk işinde nihai olarak başarısız oldu. '.($e?->getMessage() ?? ''), 0, 1000),
        ]);
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-posta', default => $channel,
        };
    }
}
