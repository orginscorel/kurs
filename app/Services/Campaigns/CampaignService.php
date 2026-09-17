<?php

namespace App\Services\Campaigns;

use App\Exceptions\BusinessRuleException;
use App\Models\MessageCampaign;
use App\Models\MessageCampaignRecipient;
use App\Models\User;
use App\Services\Communication\CommunicationAudit;
use App\Support\BranchContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Toplu gönderim yaşam döngüsü: taslak → (önizleme) → onay [alıcı listesi dondurulur] → zamanlandı/gönderiliyor
 * → tamamlandı. Asıl gönderim kuyrukta (CampaignSender) parça parça ve hız sınırıyla yapılır.
 */
class CampaignService
{
    public function __construct(
        private readonly CampaignAudience $audience,
        private readonly CampaignPlanner $planner,
    ) {}

    public function saveDraft(?MessageCampaign $campaign, array $data, User $user): MessageCampaign
    {
        if ($campaign && ! $campaign->isEditable()) {
            throw new BusinessRuleException('Onaylanmış gönderim düzenlenemez. Kopyasını oluşturup yeniden gönderebilirsiniz.', 'campaign_locked');
        }

        $campaign ??= new MessageCampaign(['created_by' => $user->id, 'status' => 'draft']);
        $campaign->fill([
            'name' => $data['name'],
            'channels' => array_values(array_unique($data['channels'])),
            'is_commercial' => (bool) ($data['is_commercial'] ?? false),
            'audience' => self::cleanAudience($data['audience'] ?? []),
            'options' => ['sms_opt_out' => (bool) ($data['options']['sms_opt_out'] ?? false)],
            'sms_body' => $data['sms_body'] ?? null,
            'email_subject' => $data['email_subject'] ?? null,
            'email_body' => $data['email_body'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);
        $isNew = ! $campaign->exists;
        $campaign->save();

        CommunicationAudit::log($isNew ? 'communication.campaign.create' : 'communication.campaign.update',
            ($isNew ? 'Toplu gönderim taslağı oluşturdu: ' : 'Toplu gönderim taslağını güncelledi: ').$campaign->name, $campaign);

        return $campaign;
    }

    /** @return array{rows: list<array>, summary: array} */
    public function plan(array $content): array
    {
        $branchId = app(BranchContext::class)->require();
        $people = $this->audience->resolve(self::cleanAudience($content['audience'] ?? []));

        return $this->planner->plan($branchId, $people, $content, [
            'sms' => CampaignChannels::integration('sms')?->config() ?? [],
            'email' => [],
        ]);
    }

    /** Önizleme: sayılar + kişiselleştirilmiş örnekler + kanal hazır mı. */
    public function preview(array $content): array
    {
        $summary = $this->plan($content)['summary'];
        $summary['readiness'] = $this->readiness($content);

        return $summary;
    }

    /** @return array<string, array{ok:bool, reason:?string}> */
    public function readiness(array $content): array
    {
        $out = [];
        $channels = $content['channels'] ?? [];
        if (in_array('sms', $channels, true)) {
            $integration = CampaignChannels::integration('sms');
            $ready = CampaignChannels::smsReady($integration);
            if ($ready['ok'] && ($content['is_commercial'] ?? false) && $integration->provider !== 'simulation') {
                $config = $integration->config();
                if (empty($config['iys_brand_code'])) {
                    $ready = ['ok' => false, 'reason' => 'Ticari SMS için İYS marka kodu girilmeli (Ayarlar › Mesaj kanalları › SMS).'];
                } elseif (! CampaignText::smsOptOut($config)) {
                    $ready = ['ok' => false, 'reason' => 'Ticari SMS için ret (RET) numarası girilmeli (Ayarlar › Mesaj kanalları › SMS).'];
                }
            }
            $out['sms'] = $ready;
        }
        if (in_array('email', $channels, true)) {
            $out['email'] = CampaignChannels::emailReady(CampaignChannels::integration('email'));
        }

        return $out;
    }

    /**
     * Gönderimi onaylar: alıcı listesi dondurulur, kuyruk (zamanlayıcı) devralır.
     * $confirm: kullanıcının onay penceresinde gördüğü sayılar — arada değiştiyse yeniden onay istenir.
     */
    public function approve(MessageCampaign $campaign, array $confirm, User $user): MessageCampaign
    {
        if ($campaign->status !== 'draft') {
            throw new BusinessRuleException('Bu gönderim zaten onaylanmış.', 'campaign_not_draft');
        }

        $content = $campaign->only(['channels', 'is_commercial', 'audience', 'options', 'sms_body', 'email_subject', 'email_body']);
        $this->validateContent($content);

        $notReady = array_filter($this->readiness($content), fn ($r) => ! $r['ok']);
        if ($notReady) {
            throw new BusinessRuleException(implode(' ', array_column($notReady, 'reason')), 'channel_not_connected', ['channels' => array_keys($notReady)]);
        }

        $plan = $this->plan($content);
        $summary = $plan['summary'];
        if ($summary['unknown_vars']) {
            throw new BusinessRuleException('Tanımsız değişken: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', $summary['unknown_vars'])).'.', 'unknown_variables');
        }

        $sendable = array_sum(array_map(fn ($c) => $c['sendable'], $summary['channels']));
        $parts = (int) ($summary['channels']['sms']['parts'] ?? 0);
        if ($sendable === 0) {
            throw new BusinessRuleException('Gönderilebilecek alıcı yok (adres, izin ya da ret listesi nedeniyle hepsi atlanıyor).', 'no_recipients');
        }
        if ((int) ($confirm['sendable'] ?? -1) !== $sendable || (int) ($confirm['sms_parts'] ?? 0) !== $parts) {
            throw new BusinessRuleException('Alıcı sayısı siz onaylarken değişti. Lütfen güncel sayıları kontrol edip yeniden onaylayın.', 'estimate_changed', [
                'sendable' => $sendable, 'sms_parts' => $parts,
            ], 409);
        }

        $scheduledAt = $campaign->scheduled_at && $campaign->scheduled_at->isFuture() ? $campaign->scheduled_at : null;

        DB::transaction(function () use ($campaign, $plan, $summary, $scheduledAt, $user, $sendable) {
            $now = now();
            foreach (array_chunk($plan['rows'], 500) as $chunk) {
                MessageCampaignRecipient::query()->insert(array_map(fn ($r) => [
                    'campaign_id' => $campaign->id, 'branch_id' => $campaign->branch_id,
                    'channel' => $r['channel'], 'recipient_type' => $r['recipient_type'], 'recipient_id' => $r['recipient_id'],
                    'student_id' => $r['student_id'], 'group' => $r['group'], 'name' => $r['name'], 'to' => $r['to'],
                    'vars' => json_encode($r['vars'], JSON_UNESCAPED_UNICODE), 'status' => $r['status'], 'skip_reason' => $r['skip_reason'],
                    'sms_parts' => $r['sms_parts'], 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }

            $campaign->forceFill([
                'status' => $scheduledAt ? 'scheduled' : 'sending',
                'scheduled_at' => $scheduledAt,
                'estimate' => array_diff_key($summary, ['samples' => true]),
                'recipients_total' => $sendable,
                'approved_by' => $user->id,
                'approved_at' => $now,
                'started_at' => $scheduledAt ? null : $now,
            ])->save();
        });

        $channelsText = collect($summary['channels'])->map(fn ($c, $ch) => $c['sendable'].' '.($ch === 'sms' ? 'SMS alıcısı ('.$c['parts'].' parça)' : 'e-posta alıcısı'))->implode(', ');
        CommunicationAudit::log('communication.campaign.approve',
            "Toplu gönderimi onayladı: {$campaign->name} — {$channelsText}".($scheduledAt ? ', zaman: '.$scheduledAt->format('d.m.Y H:i') : ''),
            $campaign, ['estimate' => ['sendable' => $sendable, 'sms_parts' => $parts, 'commercial' => $campaign->is_commercial]]);

        return $campaign->refresh();
    }

    public function cancel(MessageCampaign $campaign): MessageCampaign
    {
        if (! in_array($campaign->status, ['scheduled', 'sending'], true)) {
            throw new BusinessRuleException('Yalnız zamanlanmış ya da süren gönderim iptal edilebilir.', 'campaign_not_active');
        }

        DB::transaction(function () use ($campaign) {
            $skipped = MessageCampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'pending')
                ->update(['status' => 'skipped', 'skip_reason' => 'cancelled', 'updated_at' => now()]);
            $campaign->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            CommunicationAudit::log('communication.campaign.cancel', "Toplu gönderimi iptal etti: {$campaign->name} ({$skipped} alıcıya gönderilmedi)", $campaign);
        });

        return $campaign->refresh();
    }

    /** Başarısız alıcıları yeniden sıraya alır. */
    public function retryFailed(MessageCampaign $campaign, ?array $recipientIds = null): int
    {
        if (! in_array($campaign->status, ['sending', 'completed'], true)) {
            throw new BusinessRuleException('Yalnız gönderilmiş ya da süren gönderimde yeniden deneme yapılabilir.', 'campaign_not_retryable');
        }

        $count = 0;
        DB::transaction(function () use ($campaign, $recipientIds, &$count) {
            $count = MessageCampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'failed')
                ->when($recipientIds, fn ($q) => $q->whereIn('id', $recipientIds))
                ->update(['status' => 'pending', 'error' => null, 'updated_at' => now()]);
            if ($count > 0) {
                $campaign->forceFill(['status' => 'sending', 'completed_at' => null])->save();
            }
        });

        if ($count > 0) {
            CommunicationAudit::log('communication.campaign.retry', "Toplu gönderimde {$count} başarısız alıcıyı yeniden sıraya aldı: {$campaign->name}", $campaign);
        }

        return $count;
    }

    public function duplicate(MessageCampaign $campaign, User $user): MessageCampaign
    {
        $copy = $campaign->replicate(['status', 'scheduled_at', 'estimate', 'recipients_total', 'approved_by', 'approved_at', 'started_at', 'completed_at', 'cancelled_at', 'created_by']);
        $copy->forceFill(['name' => mb_substr($campaign->name.' (kopya)', 0, 160), 'status' => 'draft', 'created_by' => $user->id, 'recipients_total' => 0])->save();
        CommunicationAudit::log('communication.campaign.create', 'Toplu gönderimi kopyaladı: '.$copy->name, $copy);

        return $copy;
    }

    /** Durum sayıları (kanal bazında). @return array<string, array<string, int>> */
    public static function counts(MessageCampaign $campaign): array
    {
        $out = [];
        MessageCampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->selectRaw('channel, status, count(*) as c, coalesce(sum(sms_parts),0) as parts')
            ->groupBy('channel', 'status')->get()
            ->each(function ($r) use (&$out) {
                $out[$r->channel] ??= ['total' => 0, 'pending' => 0, 'sending' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0, 'parts_sent' => 0];
                $out[$r->channel][$r->status] = (int) $r->c;
                $out[$r->channel]['total'] += (int) $r->c;
                if (in_array($r->status, ['sent', 'delivered'], true)) {
                    $out[$r->channel]['parts_sent'] += (int) $r->parts;
                }
            });

        return $out;
    }

    public function validateContent(array $content): void
    {
        $channels = $content['channels'] ?? [];
        if (! $channels) {
            throw new BusinessRuleException('En az bir kanal seçin.', 'no_channel');
        }
        if (! ($content['audience']['groups'] ?? [])) {
            throw new BusinessRuleException('En az bir alıcı grubu seçin.', 'no_audience');
        }
        if (in_array('sms', $channels, true) && trim((string) ($content['sms_body'] ?? '')) === '') {
            throw new BusinessRuleException('SMS metni boş olamaz.', 'sms_body_required');
        }
        if (in_array('email', $channels, true) && (trim((string) ($content['email_subject'] ?? '')) === '' || trim((string) ($content['email_body'] ?? '')) === '')) {
            throw new BusinessRuleException('E-posta konusu ve metni boş olamaz.', 'email_body_required');
        }
    }

    public static function cleanAudience(array $audience): array
    {
        $ints = fn ($v) => array_values(array_unique(array_map('intval', array_filter((array) $v, 'is_numeric'))));
        $strings = fn ($v) => array_values(array_unique(array_filter(array_map('strval', (array) $v))));

        return [
            'groups' => array_values(array_intersect(array_keys(CampaignAudience::GROUPS), $strings($audience['groups'] ?? []))),
            'student_statuses' => $strings($audience['student_statuses'] ?? []),
            'class_group_ids' => $ints($audience['class_group_ids'] ?? []),
            'program_ids' => $ints($audience['program_ids'] ?? []),
            'lead_stages' => $strings($audience['lead_stages'] ?? []),
            'manual' => array_values(array_map(fn ($r) => [
                'name' => mb_substr(trim((string) ($r['name'] ?? '')), 0, 120),
                'phone' => ($p = trim((string) ($r['phone'] ?? ''))) !== '' ? mb_substr($p, 0, 30) : null,
                'email' => ($e = trim((string) ($r['email'] ?? ''))) !== '' ? mb_substr($e, 0, 160) : null,
            ], array_slice(array_filter((array) ($audience['manual'] ?? []), 'is_array'), 0, CampaignAudience::MAX_MANUAL))),
        ];
    }

    /** Zamanı gelmiş zamanlanmış gönderimleri başlatır (tüm şubeler). */
    public static function startDue(?Carbon $now = null): int
    {
        $now ??= now();

        return MessageCampaign::query()->withoutGlobalScope('branch')
            ->where('status', 'scheduled')->where('scheduled_at', '<=', $now)
            ->update(['status' => 'sending', 'started_at' => $now, 'updated_at' => $now]);
    }
}
