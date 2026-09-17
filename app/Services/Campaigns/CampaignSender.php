<?php

namespace App\Services\Campaigns;

use App\Models\Integration;
use App\Models\MessageCampaign;
use App\Models\MessageCampaignRecipient;
use App\Models\OutboundMessage;
use App\Services\Messaging\ProviderFactory;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\Sms\SmsLength;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Log;

/**
 * Kuyruk işçisinin gövdesi: bir gönderimin sıradaki alıcılarını hız sınırı içinde gönderir.
 *  - SMS: sürücünün tek istekte kabul ettiği kadar (NetGSM/Mutlucell/VatanSMS n:n) toplu istek.
 *  - E-posta: tek tek SMTP.
 *  - Dakikadaki gönderim sınırı entegrasyon ayarındaki `rate_per_minute` (varsayılan SMS 300, e-posta 30).
 *  - Bir çalışmada en fazla ~40 sn çalışır; kalan alıcılar bir sonraki dakikaya kalır.
 * Her alıcı için bir `outbound_messages` satırı yazılır (Mesaj geçmişinde ve raporda görünür).
 */
class CampaignSender
{
    public const TIME_BUDGET_SECONDS = 40;

    public function __construct(private readonly ProviderFactory $factory) {}

    /** @return array{sent:int, failed:int, remaining:int} */
    public function process(MessageCampaign $campaign, ?float $deadline = null): array
    {
        $deadline ??= microtime(true) + self::TIME_BUDGET_SECONDS;

        return app(BranchContext::class)->run($campaign->branch_id, function () use ($campaign, $deadline) {
            $totals = ['sent' => 0, 'failed' => 0, 'remaining' => 0];
            if ($campaign->status !== 'sending') {
                return $totals;
            }

            foreach ($campaign->channels ?? [] as $channel) {
                if (! in_array($channel, ['sms', 'email'], true)) {
                    continue;
                }
                $r = $this->processChannel($campaign, $channel, $deadline);
                $totals['sent'] += $r['sent'];
                $totals['failed'] += $r['failed'];
            }

            // Yarıda kalmış (işçi çöktü) "sending" alıcıları 10 dk sonra yeniden sıraya al
            MessageCampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'sending')
                ->where('updated_at', '<', now()->subMinutes(10))
                ->update(['status' => 'pending', 'updated_at' => now()]);

            $totals['remaining'] = MessageCampaignRecipient::query()->where('campaign_id', $campaign->id)
                ->whereIn('status', ['pending', 'sending'])->count();

            if ($totals['remaining'] === 0) {
                $campaign->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            }

            return $totals;
        });
    }

    /** @return array{sent:int, failed:int} */
    private function processChannel(MessageCampaign $campaign, string $channel, float $deadline): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $integration = CampaignChannels::integration($channel, $campaign->branch_id);
        $ready = $channel === 'sms' ? CampaignChannels::smsReady($integration) : CampaignChannels::emailReady($integration);

        $pending = MessageCampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->where('channel', $channel)->where('status', 'pending');

        if (! $ready['ok']) {
            // Kanal bağlantısı kesildi: bekleyenler başarısız işaretlenir, bağlantı kurulunca "yeniden dene" ile gönderilir.
            $result['failed'] = (clone $pending)->update(['status' => 'failed', 'error' => mb_substr((string) $ready['reason'], 0, 500), 'updated_at' => now()]);

            return $result;
        }

        $config = $integration->config();
        $allowance = CampaignChannels::rate($channel, $config) - $this->sentLastMinute($campaign->branch_id, $channel);
        if ($allowance <= 0) {
            return $result;
        }

        $recipients = (clone $pending)->orderBy('id')->limit($allowance)->get();
        if ($recipients->isEmpty()) {
            return $result;
        }

        if ($channel === 'sms') {
            $gateway = $this->factory->smsGateway($integration->provider);
            $mode = in_array($config['encoding'] ?? 'tr', SmsLength::MODES, true) ? ($config['encoding'] ?? 'tr') : 'tr';
            $optOut = ($campaign->is_commercial || ! empty($campaign->options['sms_opt_out'])) ? CampaignText::smsOptOut($config) : null;

            foreach ($recipients->chunk($gateway->maxBatchSize()) as $batch) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $messages = [];
                foreach ($batch as $recipient) {
                    $body = CampaignPlanner::smsText($campaign->sms_body, $recipient->vars ?? [], $optOut);
                    $messages[$recipient->id] = $this->message($campaign, $recipient, $integration, $body, null, SmsLength::parts($body, $mode));
                }

                try {
                    $outcomes = $gateway->sendBatch(array_values($messages), $config, ['commercial' => (bool) $campaign->is_commercial]);
                } catch (\Throwable $e) {
                    Log::warning('Toplu SMS gönderim hatası', ['campaign_id' => $campaign->id, 'exception' => $e::class]);
                    $outcomes = [];
                }

                foreach ($batch as $recipient) {
                    $message = $messages[$recipient->id];
                    $outcome = $outcomes[$message->id] ?? ProviderResult::fail('SMS sağlayıcısı bu alıcı için sonuç döndürmedi.');
                    $this->record($recipient, $message, $outcome) ? $result['sent']++ : $result['failed']++;
                }
            }

            return $result;
        }

        $provider = $this->factory->make('email', $integration->provider);
        foreach ($recipients as $recipient) {
            if (microtime(true) > $deadline) {
                break;
            }
            $message = $this->message($campaign, $recipient, $integration,
                CampaignText::render($campaign->email_body, $recipient->vars ?? []),
                mb_substr(CampaignText::render($campaign->email_subject, $recipient->vars ?? []), 0, 200), null);

            try {
                $outcome = $provider->send($message, $config);
            } catch (\Throwable $e) {
                Log::warning('Toplu e-posta gönderim hatası', ['campaign_id' => $campaign->id, 'exception' => $e::class]);
                $outcome = ProviderResult::fail('E-posta gönderilemedi.');
            }
            $this->record($recipient, $message, $outcome) ? $result['sent']++ : $result['failed']++;
        }

        return $result;
    }

    private function message(MessageCampaign $campaign, MessageCampaignRecipient $recipient, Integration $integration, string $body, ?string $subject, ?int $parts): OutboundMessage
    {
        $message = $recipient->outbound_message_id ? OutboundMessage::query()->find($recipient->outbound_message_id) : null;
        $message ??= new OutboundMessage;

        $message->fill([
            'branch_id' => $campaign->branch_id,
            'channel' => $recipient->channel,
            'to' => (string) $recipient->to,
            'recipient_type' => $recipient->recipient_type,
            'recipient_id' => $recipient->recipient_id,
            'student_id' => $recipient->student_id,
            'subject' => $subject,
            'body' => $body,
            'provider' => $integration->provider,
            'status' => 'sending',
            'trigger' => 'campaign:'.$campaign->id,
            'created_by' => $campaign->approved_by,
            'campaign_id' => $campaign->id,
            'sms_parts' => $parts,
            'is_commercial' => (bool) $campaign->is_commercial,
            'error' => null,
        ]);
        $message->attempts = (int) $message->attempts + 1;
        $message->save();

        $recipient->forceFill(['status' => 'sending', 'outbound_message_id' => $message->id, 'sms_parts' => $parts ?? $recipient->sms_parts])->save();

        return $message;
    }

    private function record(MessageCampaignRecipient $recipient, OutboundMessage $message, ProviderResult $outcome): bool
    {
        if ($outcome->success) {
            $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $outcome->providerMessageId, 'error' => null])->save();
            $recipient->forceFill(['status' => 'sent', 'error' => null])->save();

            return true;
        }

        $error = mb_substr($outcome->error ?? 'Bilinmeyen hata.', 0, 500);
        $message->forceFill(['status' => 'failed', 'error' => $error])->save();
        $recipient->forceFill(['status' => 'failed', 'error' => $error])->save();

        return false;
    }

    private function sentLastMinute(int $branchId, string $channel): int
    {
        return OutboundMessage::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->where('channel', $channel)->whereNotNull('campaign_id')
            ->where('updated_at', '>=', now()->subMinute())
            ->whereIn('status', ['sent', 'failed', 'delivered', 'sending'])
            ->count();
    }

    /**
     * Teslim raporlarını sağlayıcıdan çeker (SMS). Son 3 günde gönderilmiş, henüz "iletildi" olmamış mesajlar.
     *
     * @return int güncellenen mesaj sayısı
     */
    public function syncDeliveryReports(int $branchId, int $limit = 500): int
    {
        return app(BranchContext::class)->run($branchId, function () use ($branchId, $limit) {
            $integration = CampaignChannels::integration('sms', $branchId);
            $gateway = $integration ? $this->factory->smsGateway($integration->provider) : null;
            if (! $gateway || ! CampaignChannels::connected($integration)) {
                return 0;
            }

            $messages = OutboundMessage::query()
                ->where('channel', 'sms')->whereNotNull('campaign_id')->where('status', 'sent')
                ->where('provider', $integration->provider)->whereNotNull('provider_message_id')
                ->whereBetween('sent_at', [now()->subDays(3), now()->subMinute()])
                ->orderBy('id')->limit($limit)->get();
            if ($messages->isEmpty()) {
                return 0;
            }

            try {
                $report = $gateway->deliveryReport($messages->all(), $integration->config());
            } catch (\Throwable $e) {
                Log::warning('SMS teslim raporu alınamadı', ['branch_id' => $branchId, 'exception' => $e::class]);

                return 0;
            }

            $updated = 0;
            foreach ($messages as $m) {
                $row = $report[$m->id] ?? null;
                if (! $row || $row['status'] === 'pending') {
                    continue;
                }
                if ($row['status'] === 'delivered') {
                    $m->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
                    MessageCampaignRecipient::query()->where('outbound_message_id', $m->id)->update(['status' => 'delivered', 'updated_at' => now()]);
                } else {
                    $error = mb_substr((string) ($row['error'] ?? 'Teslim edilemedi.'), 0, 500);
                    $m->forceFill(['status' => 'failed', 'error' => $error])->save();
                    MessageCampaignRecipient::query()->where('outbound_message_id', $m->id)->update(['status' => 'failed', 'error' => $error, 'updated_at' => now()]);
                }
                $updated++;
            }

            return $updated;
        });
    }
}
