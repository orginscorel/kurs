<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\ClassGroup;
use App\Models\Lead;
use App\Models\MessageCampaign;
use App\Models\MessageCampaignRecipient;
use App\Models\MessageTemplate;
use App\Models\Program;
use App\Models\Student;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignChannels;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\CampaignText;
use App\Services\Communication\CommunicationAudit;
use App\Services\Messaging\Sms\SmsLength;
use App\Support\Sensitive;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Toplu e-posta / SMS gönderimi (İletişim › Toplu gönderim). */
class CampaignController extends ApiController
{
    public function __construct(private readonly CampaignService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = MessageCampaign::query()->with(['creator:id,name', 'approver:id,name'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('channel'), fn (Builder $q) => $q->whereJsonContains('channels', $request->query('channel')))
            ->when($request->filled('q'), fn (Builder $q) => $q->where('name', 'like', '%'.$request->query('q').'%'));
        $this->applyDateRange($query, $request, 'created_at');
        $this->applySort($query, $request, ['created_at' => 'created_at', 'name' => 'name', 'status' => 'status'], '-created_at');

        $page = $query->paginate($this->perPage($request, 20));
        $ids = collect($page->items())->pluck('id');
        $counts = MessageCampaignRecipient::query()->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, status, count(*) c')->groupBy('campaign_id', 'status')->get()
            ->groupBy('campaign_id')->map(fn ($rows) => $rows->pluck('c', 'status')->map(fn ($v) => (int) $v)->all());

        $stats = MessageCampaign::query()->selectRaw("count(*) total, sum(status='draft') drafts, sum(status in ('scheduled','sending')) active, sum(status='completed') completed")->first();

        return $this->paginated($page, fn (MessageCampaign $c) => $this->row($c, $counts[$c->id] ?? []), ['stats' => [
            'total' => (int) $stats->total, 'drafts' => (int) $stats->drafts, 'active' => (int) $stats->active, 'completed' => (int) $stats->completed,
        ]]);
    }

    public function show(MessageCampaign $campaign): JsonResponse
    {
        $campaign->load(['creator:id,name', 'approver:id,name']);

        return response()->json(['data' => array_merge($this->row($campaign, []), [
            'audience' => $campaign->audience,
            'options' => $campaign->options ?? [],
            'sms_body' => $campaign->sms_body,
            'email_subject' => $campaign->email_subject,
            'email_body' => $campaign->email_body,
            'estimate' => $campaign->estimate,
            'counts' => (object) CampaignService::counts($campaign),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = $this->service->saveDraft(null, $this->validated($request), $request->user());

        return response()->json(['message' => 'Taslak kaydedildi.', 'data' => ['id' => $campaign->id]], 201);
    }

    public function update(Request $request, MessageCampaign $campaign): JsonResponse
    {
        $this->service->saveDraft($campaign, $this->validated($request), $request->user());

        return $this->ok('Taslak güncellendi.', ['data' => ['id' => $campaign->id]]);
    }

    public function destroy(MessageCampaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            throw new BusinessRuleException('Yalnız taslaklar silinebilir; süren gönderimi iptal edebilirsiniz.', 'campaign_not_draft');
        }
        $campaign->delete();
        CommunicationAudit::log('communication.campaign.delete', 'Toplu gönderim taslağını sildi: '.$campaign->name, $campaign);

        return $this->ok('Taslak silindi.');
    }

    /** Kaydetmeden önizleme: sayılar, SMS parça/kredi tahmini, kişiselleştirilmiş örnekler. */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request, draft: true);

        return response()->json(['data' => $this->service->preview($data)]);
    }

    public function approve(Request $request, MessageCampaign $campaign): JsonResponse
    {
        $confirm = $request->validate([
            'sendable' => ['required', 'integer', 'min:0'],
            'sms_parts' => ['required', 'integer', 'min:0'],
        ]);
        $campaign = $this->service->approve($campaign, $confirm, $request->user());

        return $this->ok($campaign->status === 'scheduled'
            ? 'Gönderim zamanlandı: '.$campaign->scheduled_at->format('d.m.Y H:i').'.'
            : 'Gönderim başladı. Alıcılar kuyrukta sırayla işlenecek.', ['data' => ['id' => $campaign->id, 'status' => $campaign->status]]);
    }

    public function cancel(MessageCampaign $campaign): JsonResponse
    {
        $this->service->cancel($campaign);

        return $this->ok('Gönderim iptal edildi. Henüz gönderilmemiş alıcılara ileti gitmeyecek.');
    }

    public function retry(Request $request, MessageCampaign $campaign): JsonResponse
    {
        $ids = $request->validate(['ids' => ['nullable', 'array', 'max:5000'], 'ids.*' => ['integer']])['ids'] ?? null;
        $count = $this->service->retryFailed($campaign, $ids);

        return $this->ok($count > 0 ? "{$count} alıcı yeniden sıraya alındı." : 'Yeniden denenecek başarısız alıcı yok.', ['count' => $count]);
    }

    public function duplicate(Request $request, MessageCampaign $campaign): JsonResponse
    {
        $copy = $this->service->duplicate($campaign, $request->user());

        return response()->json(['message' => 'Kopya taslak oluşturuldu.', 'data' => ['id' => $copy->id]], 201);
    }

    public function recipients(Request $request, MessageCampaign $campaign): JsonResponse
    {
        $query = MessageCampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->with('message:id,status,error,sent_at,delivered_at,provider')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->query('channel')))
            ->when($request->filled('group'), fn (Builder $q) => $q->where('group', $request->query('group')))
            ->when($request->filled('skip_reason'), fn (Builder $q) => $q->where('skip_reason', $request->query('skip_reason')))
            ->when($request->filled('q'), fn (Builder $q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->query('q').'%')->orWhere('to', 'like', '%'.$request->query('q').'%')));
        $this->applySort($query, $request, ['name' => 'name', 'status' => 'status', 'id' => 'id', 'updated_at' => 'updated_at'], 'id');

        $canSeeFull = $request->user()->can('students.view_sensitive');

        return $this->paginated($query->paginate($this->perPage($request, 30)), fn (MessageCampaignRecipient $r) => [
            'id' => $r->id,
            'channel' => $r->channel,
            'group' => $r->group,
            'group_label' => CampaignAudience::GROUPS[$r->group] ?? $r->group,
            'recipient_type' => $r->recipient_type,
            'recipient_id' => $r->recipient_id,
            'student_id' => $r->student_id,
            'name' => $r->name,
            'to' => $r->to ? ($canSeeFull ? $r->to : ($r->channel === 'sms' ? Sensitive::maskPhone($r->to) : self::maskEmail($r->to))) : null,
            'status' => $r->status,
            'status_label' => MessageCampaignRecipient::STATUSES[$r->status] ?? $r->status,
            'skip_reason' => $r->skip_reason,
            'skip_label' => $r->skip_reason ? (MessageCampaignRecipient::SKIP_REASONS[$r->skip_reason] ?? $r->skip_reason) : null,
            'sms_parts' => $r->sms_parts,
            'error' => $r->error,
            'simulated' => $r->message?->provider === 'simulation',
            'sent_at' => $r->message?->sent_at,
            'delivered_at' => $r->message?->delivered_at,
            'updated_at' => $r->updated_at,
        ]);
    }

    /** Ekranın ihtiyaç duyduğu sabitler + kanal durumu. */
    public function options(Request $request): JsonResponse
    {
        $sms = CampaignChannels::integration('sms');
        $email = CampaignChannels::integration('email');
        $smsConfig = $sms?->config() ?? [];

        return response()->json([
            'groups' => CampaignAudience::GROUPS,
            'student_statuses' => Student::STATUSES,
            'lead_stages' => Lead::STAGES,
            'open_lead_stages' => CampaignAudience::OPEN_LEAD_STAGES,
            'class_groups' => ClassGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'program_id']),
            'programs' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'variables' => CampaignText::VARIABLES,
            'templates' => MessageTemplate::query()->whereIn('channel', ['sms', 'email'])->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $request->user()->branch_id))
                ->orderBy('name')->get(['id', 'channel', 'name', 'subject', 'body']),
            'channels' => [
                'sms' => CampaignChannels::smsReady($sms) + [
                    'provider' => $sms?->provider,
                    'simulation' => $sms?->provider === 'simulation',
                    'encoding' => in_array($smsConfig['encoding'] ?? 'tr', SmsLength::MODES, true) ? ($smsConfig['encoding'] ?? 'tr') : 'tr',
                    'header' => $smsConfig['header'] ?? null,
                    'unit_price' => $smsConfig['unit_price'] ?? null,
                    'opt_out_text' => CampaignText::smsOptOut($smsConfig),
                    'iys_brand_code_set' => ! empty($smsConfig['iys_brand_code']),
                ],
                'email' => CampaignChannels::emailReady($email) + [
                    'provider' => $email?->provider,
                    'simulation' => $email?->provider === 'simulation',
                    'from' => ($email?->config()['from_address'] ?? null),
                ],
                'whatsapp' => ['ok' => CampaignChannels::connected(CampaignChannels::integration('whatsapp'))],
            ],
            'limits' => ['sms_body' => 1000, 'email_body' => 20000, 'manual' => CampaignAudience::MAX_MANUAL],
        ]);
    }

    /** Elle yapıştırılan liste → ayrıştırılmış satırlar (kaydetmeden). */
    public function parseManual(Request $request): JsonResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:200000']])['text'];

        return response()->json(['data' => CampaignAudience::parseManual($text)]);
    }

    private function row(MessageCampaign $c, array $counts): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'channels' => $c->channels ?? [],
            'is_commercial' => (bool) $c->is_commercial,
            'status' => $c->status,
            'status_label' => MessageCampaign::STATUSES[$c->status] ?? $c->status,
            'scheduled_at' => $c->scheduled_at,
            'recipients_total' => (int) $c->recipients_total,
            'sms_parts' => (int) ($c->estimate['channels']['sms']['parts'] ?? 0),
            'counts' => $counts ? [
                'sent' => ($counts['sent'] ?? 0) + ($counts['delivered'] ?? 0),
                'delivered' => $counts['delivered'] ?? 0,
                'failed' => $counts['failed'] ?? 0,
                'pending' => ($counts['pending'] ?? 0) + ($counts['sending'] ?? 0),
                'skipped' => $counts['skipped'] ?? 0,
            ] : null,
            'created_by' => $c->creator?->name,
            'approved_by' => $c->approver?->name,
            'approved_at' => $c->approved_at,
            'started_at' => $c->started_at,
            'completed_at' => $c->completed_at,
            'created_at' => $c->created_at,
        ];
    }

    private function validated(Request $request, bool $draft = false): array
    {
        return $request->validate([
            'name' => [$draft ? 'nullable' : 'required', 'string', 'max:160'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['sms', 'email'])],
            'is_commercial' => ['boolean'],
            'audience' => ['required', 'array'],
            'audience.groups' => ['array'],
            'audience.groups.*' => [Rule::in(array_keys(CampaignAudience::GROUPS))],
            'audience.student_statuses' => ['array'],
            'audience.student_statuses.*' => [Rule::in(array_keys(Student::STATUSES))],
            'audience.class_group_ids' => ['array'],
            'audience.class_group_ids.*' => ['integer'],
            'audience.program_ids' => ['array'],
            'audience.program_ids.*' => ['integer'],
            'audience.lead_stages' => ['array'],
            'audience.lead_stages.*' => [Rule::in(array_keys(Lead::STAGES))],
            'audience.manual' => ['array', 'max:'.CampaignAudience::MAX_MANUAL],
            'audience.manual.*.name' => ['nullable', 'string', 'max:120'],
            'audience.manual.*.phone' => ['nullable', 'string', 'max:30'],
            'audience.manual.*.email' => ['nullable', 'string', 'max:160'],
            'options' => ['nullable', 'array'],
            'options.sms_opt_out' => ['boolean'],
            'sms_body' => ['nullable', 'string', 'max:1000'],
            'email_subject' => ['nullable', 'string', 'max:200'],
            'email_body' => ['nullable', 'string', 'max:20000'],
            'scheduled_at' => ['nullable', 'date', $draft ? 'nullable' : 'after:now'],
        ], [
            'scheduled_at.after' => 'Gönderim zamanı ileri bir tarih olmalı.',
            'name.required' => 'Gönderime bir ad verin (ör. "Eylül kayıt duyurusu").',
            'channels.required' => 'En az bir kanal (SMS ya da e-posta) seçin.',
            'channels.min' => 'En az bir kanal (SMS ya da e-posta) seçin.',
        ], [
            'name' => 'Gönderim adı', 'channels' => 'Kanal', 'audience' => 'Alıcılar', 'audience.groups' => 'Alıcı grupları',
            'audience.manual' => 'Elle eklenen kişiler', 'audience.manual.*.phone' => 'Elle eklenen telefon',
            'audience.manual.*.email' => 'Elle eklenen e-posta', 'sms_body' => 'SMS metni', 'email_subject' => 'E-posta konusu',
            'email_body' => 'E-posta metni', 'scheduled_at' => 'Gönderim zamanı',
        ]);
    }

    private static function maskEmail(string $email): string
    {
        return (string) preg_replace('/^(.).*(@.*)$/u', '$1•••$2', $email);
    }
}
