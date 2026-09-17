<?php

namespace App\Services\Messaging;

use App\Jobs\SendOutboundMessage;
use App\Models\CommunicationConsent;
use App\Models\Integration;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Giden mesaj kuyruğu. Burada gerçek gönderim YAPILMAZ — yalnızca `outbound_messages`
 * satırı oluşturulur ve `SendOutboundMessage` işi kuyruğa alınır. İzin reddi, şablon
 * bulunamaması ya da alıcı adresi eksikliği gibi kesin durumlar burada `failed` yazılır
 * (sessiz kaybolma yok); geçici sağlayıcı hataları işte ele alınır.
 */
class MessageDispatcher
{
    /**
     * @param  array{
     *   channel: string, to: ?string, recipient?: ?Model, student_id?: ?int,
     *   template_key?: ?string, vars?: array, body?: ?string, subject?: ?string,
     *   media_path?: ?string, dedupe_key?: ?string, trigger?: ?string, created_by?: ?int,
     *   scheduled_at?: ?\DateTimeInterface, purpose?: string, consentable?: ?Model,
     * }  $input
     */
    public function queue(array $input): ?OutboundMessage
    {
        $branchId = $input['branch_id'] ?? app(BranchContext::class)->require();
        $dedupeKey = $input['dedupe_key'] ?? null;

        if ($dedupeKey && OutboundMessage::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $channel = $input['channel'];
        $recipient = $input['recipient'] ?? null;
        $consentable = $input['consentable'] ?? $recipient;
        $purpose = $input['purpose'] ?? 'informational';

        [$body, $subject, $providerTemplate, $templateError] = $this->render($branchId, $channel, $input);

        $base = [
            'branch_id' => $branchId,
            'channel' => $channel,
            'to' => $input['to'] ?? '',
            'recipient_type' => $recipient?->getMorphClass(),
            'recipient_id' => $recipient?->getKey(),
            'student_id' => $input['student_id'] ?? null,
            'template_key' => $input['template_key'] ?? null,
            'subject' => $subject,
            'body' => $body ?? ($input['body'] ?? ''),
            'media_path' => $input['media_path'] ?? null,
            'dedupe_key' => $dedupeKey,
            'trigger' => $input['trigger'] ?? null,
            'created_by' => $input['created_by'] ?? null,
            'scheduled_at' => $input['scheduled_at'] ?? null,
            'provider' => null,
            'status' => 'queued',
        ];

        $failReason = null;
        if ($templateError) {
            $failReason = $templateError;
        } elseif (empty($base['to'])) {
            $failReason = 'Alıcı için '.$this->channelLabel($channel).' adresi/numarası bulunamadı.';
        } elseif ($consentable && $this->consentDenied($consentable, $channel, $purpose)) {
            $failReason = 'Alıcı bu kanal için iletişim izni vermemiş.';
        }

        if ($failReason) {
            $base['status'] = 'failed';
            $base['error'] = $failReason;
        }

        try {
            $message = OutboundMessage::query()->create($base);
        } catch (QueryException $e) {
            // dedupe_key eşzamanlı çift kayıt (yarış durumu) — sessizce atla.
            if (str_contains($e->getMessage(), 'dedupe_key') || str_contains($e->getMessage(), 'Duplicate')) {
                return null;
            }
            throw $e;
        }

        if ($message->status === 'queued') {
            DB::afterCommit(function () use ($message) {
                $job = SendOutboundMessage::dispatch($message->id, $message->branch_id);
                if ($message->scheduled_at && $message->scheduled_at->isFuture()) {
                    $job->delay($message->scheduled_at);
                }
            });
        }

        return $message;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} body, subject, provider_template, error */
    private function render(int $branchId, string $channel, array $input): array
    {
        $templateKey = $input['template_key'] ?? null;
        if (! $templateKey) {
            return [$input['body'] ?? '', $input['subject'] ?? null, null, null];
        }

        $template = MessageTemplate::query()
            ->where('channel', $channel)->where('key', $templateKey)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderByRaw('branch_id IS NULL')
            ->first();

        if (! $template) {
            return [null, null, null, "Mesaj şablonu bulunamadı: {$templateKey} ({$channel})."];
        }

        $vars = $input['vars'] ?? [];
        $subject = $template->subject ? preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($vars[$m[1]] ?? ''), $template->subject) : null;

        return [$template->render($vars), $subject, $template->provider_template, null];
    }

    /** Toplu gönderim önizlemesinde de kullanılır (mesaj oluşturmadan izin kontrolü). */
    public function consentDenied(Model $consentable, string $channel, string $purpose = 'informational'): bool
    {
        return CommunicationConsent::query()
            ->where('consentable_type', $consentable->getMorphClass())
            ->where('consentable_id', $consentable->getKey())
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('granted', false)
            ->exists();
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'e-posta', 'push' => 'push',
            default => $channel,
        };
    }
}
