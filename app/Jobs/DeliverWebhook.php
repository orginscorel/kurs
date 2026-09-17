<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/** Webhook teslimatı: HMAC-SHA256 imzalı gövde, en fazla 5 deneme. */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 20;

    public function __construct(public readonly int $deliveryId) {}

    /** @return array<int> */
    public function backoff(): array
    {
        return [30, 120, 300, 900]; // 30sn, 2dk, 5dk, 15dk
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('webhook')->find($this->deliveryId);
        if (! $delivery || ! $delivery->webhook || ! $delivery->webhook->is_active) {
            return;
        }

        $webhook = $delivery->webhook;
        $secret = \Illuminate\Support\Facades\Crypt::decryptString($webhook->secret_encrypted);
        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = WebhookSigner::sign($body, $secret);

        $delivery->increment('attempts');

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Erbaa-Signature' => $signature,
                'X-Erbaa-Event' => $delivery->event,
            ])->withBody($body, 'application/json')->timeout(15)->post($webhook->url);
        } catch (\Throwable $e) {
            $this->recordFailure($delivery, $webhook, null, mb_substr($e->getMessage(), 0, 1000));

            return;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => 'success', 'response_status' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 1000), 'next_attempt_at' => null,
            ])->save();
            $webhook->forceFill(['last_success_at' => now()])->save();

            return;
        }

        $this->recordFailure($delivery, $webhook, $response->status(), mb_substr($response->body(), 0, 1000));
    }

    private function recordFailure(WebhookDelivery $delivery, $webhook, ?int $status, ?string $body): void
    {
        $isLast = $this->attempts() >= $this->tries;

        $delivery->forceFill([
            'status' => $isLast ? 'failed' : 'pending',
            'response_status' => $status,
            'response_body' => $body,
            'next_attempt_at' => $isLast ? null : now()->addSeconds($this->backoff()[$this->attempts() - 1] ?? 900),
        ])->save();
        $webhook->forceFill(['last_failure_at' => now()])->save();

        if (! $isLast) {
            throw new \RuntimeException('Webhook teslimatı başarısız: HTTP '.($status ?? 'bağlantı hatası'));
        }
    }
}
