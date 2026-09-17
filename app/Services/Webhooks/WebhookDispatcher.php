<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

/**
 * Olay → webhook teslimatı. Şubedeki AKTİF ve bu olaya abone webhook'lar için
 * `webhook_deliveries` satırı oluşturur ve `DeliverWebhook` işini kuyruğa alır.
 */
class WebhookDispatcher
{
    public function dispatch(string $event, array $payload, ?int $branchId = null): void
    {
        $branchId ??= app(BranchContext::class)->id();
        if (! $branchId) {
            return;
        }

        $webhooks = Webhook::query()->where('branch_id', $branchId)->where('is_active', true)->get()
            ->filter(fn (Webhook $w) => in_array($event, $w->events ?? [], true));

        foreach ($webhooks as $webhook) {
            $delivery = WebhookDelivery::query()->create([
                'webhook_id' => $webhook->id, 'event' => $event, 'payload' => $payload, 'status' => 'pending',
            ]);

            DB::afterCommit(fn () => DeliverWebhook::dispatch($delivery->id));
        }
    }
}
