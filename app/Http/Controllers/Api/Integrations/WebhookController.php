<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Api\ApiController;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Communication\CommunicationAudit;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Giden webhook'lar: CRUD, teslimat geçmişi, tekrar gönder, test olayı. */
class WebhookController extends ApiController
{
    public function index(): JsonResponse
    {
        $items = Webhook::query()->withCount('deliveries')->orderBy('name')->get()->map(fn (Webhook $w) => $this->present($w));

        return response()->json(['data' => $items, 'events' => Webhook::EVENTS]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $secret = 'whsec_'.Str::random(40);

        $webhook = Webhook::query()->create([
            ...$data,
            'branch_id' => app(BranchContext::class)->require(),
            'secret_encrypted' => Crypt::encryptString($secret),
        ]);

        CommunicationAudit::log('communication.webhook.create', "\"{$webhook->name}\" webhook'unu oluşturdu.", $webhook);

        return response()->json(['message' => 'Webhook oluşturuldu.', 'id' => $webhook->id, 'secret' => $secret], 201);
    }

    public function update(Request $request, Webhook $webhook): JsonResponse
    {
        $webhook->update($this->validated($request));
        CommunicationAudit::log('communication.webhook.update', "\"{$webhook->name}\" webhook'unu güncelledi.", $webhook);

        return $this->ok('Webhook güncellendi.');
    }

    public function destroy(Webhook $webhook): JsonResponse
    {
        $webhook->delete();
        CommunicationAudit::log('communication.webhook.delete', "\"{$webhook->name}\" webhook'unu sildi.", $webhook);

        return $this->ok('Webhook silindi.');
    }

    public function rotateSecret(Webhook $webhook): JsonResponse
    {
        $secret = 'whsec_'.Str::random(40);
        $webhook->update(['secret_encrypted' => Crypt::encryptString($secret)]);
        CommunicationAudit::log('communication.webhook.rotate_secret', "\"{$webhook->name}\" webhook gizli anahtarını yeniledi.", $webhook);

        return response()->json(['message' => 'Gizli anahtar yenilendi.', 'secret' => $secret]);
    }

    public function deliveries(Request $request, Webhook $webhook): JsonResponse
    {
        $query = WebhookDelivery::query()->where('webhook_id', $webhook->id)->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request, 30)), fn (WebhookDelivery $d) => [
            'id' => $d->id, 'event' => $d->event, 'status' => $d->status, 'response_status' => $d->response_status,
            'attempts' => $d->attempts, 'next_attempt_at' => $d->next_attempt_at, 'created_at' => $d->created_at,
        ]);
    }

    public function redeliver(WebhookDelivery $delivery): JsonResponse
    {
        $delivery->forceFill(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null])->save();
        \App\Jobs\DeliverWebhook::dispatch($delivery->id);

        return $this->ok('Teslimat yeniden kuyruğa alındı.');
    }

    public function sendTest(Webhook $webhook): JsonResponse
    {
        $event = $webhook->events[0] ?? 'student.created';
        app(WebhookDispatcher::class)->dispatch($event, [
            'test' => true, 'message' => 'Bu bir test olayıdır.', 'sent_at' => now()->toIso8601String(),
        ], $webhook->branch_id);

        return $this->ok("Test olayı ({$event}) gönderildi.");
    }

    private function present(Webhook $webhook): array
    {
        return [
            'id' => $webhook->id, 'name' => $webhook->name, 'url' => $webhook->url, 'events' => $webhook->events,
            'is_active' => $webhook->is_active, 'last_success_at' => $webhook->last_success_at,
            'last_failure_at' => $webhook->last_failure_at, 'deliveries_count' => $webhook->deliveries_count,
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(Webhook::EVENTS)],
            'is_active' => ['boolean'],
        ]);
    }
}
