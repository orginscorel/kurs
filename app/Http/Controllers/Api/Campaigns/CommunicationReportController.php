<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Http\Controllers\Api\ApiController;
use App\Models\MessageCampaign;
use App\Services\Campaigns\CampaignChannels;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rapor Merkezi › İletişim: aylık gönderim (kanal kırılımı), başarı oranı, SMS parça/maliyet tahmini,
 * toplu gönderimler ve otomasyon/elle gönderim ayrımı. Kaynak: outbound_messages (her gönderimin tek satırı).
 */
class CommunicationReportController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = $request->date('to') ? CarbonImmutable::parse($request->date('to'))->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = $request->date('from') ? CarbonImmutable::parse($request->date('from'))->startOfDay() : $to->subMonthsNoOverflow(11)->startOfMonth();
        $branchId = app(BranchContext::class)->require();

        $base = DB::table('outbound_messages')->where('branch_id', $branchId)->whereBetween('created_at', [$from, $to])
            ->whereIn('channel', ['sms', 'email', 'whatsapp']);

        $ok = "status in ('sent','delivered','read')";
        $monthly = (clone $base)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') ym, channel, count(*) total, sum({$ok}) sent, sum(status in ('delivered','read')) delivered, sum(status='failed') failed, coalesce(sum(case when {$ok} then sms_parts else 0 end),0) parts")
            ->groupBy('ym', 'channel')->orderBy('ym')->get();

        $months = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['ym' => $m->format('Y-m'), 'sms' => 0, 'email' => 0, 'whatsapp' => 0, 'total' => 0, 'sent' => 0, 'failed' => 0, 'sms_parts' => 0, 'success_rate' => null, 'cost' => null];
        }

        $unit = $this->unitPrice($branchId);
        $channels = ['sms' => self::emptyChannel(), 'email' => self::emptyChannel(), 'whatsapp' => self::emptyChannel()];
        foreach ($monthly as $r) {
            if (! isset($months[$r->ym])) {
                continue;
            }
            $months[$r->ym][$r->channel] += (int) $r->sent;
            $months[$r->ym]['total'] += (int) $r->total;
            $months[$r->ym]['sent'] += (int) $r->sent;
            $months[$r->ym]['failed'] += (int) $r->failed;
            $channels[$r->channel]['total'] += (int) $r->total;
            $channels[$r->channel]['sent'] += (int) $r->sent;
            $channels[$r->channel]['delivered'] += (int) $r->delivered;
            $channels[$r->channel]['failed'] += (int) $r->failed;
            if ($r->channel === 'sms') {
                $months[$r->ym]['sms_parts'] += (int) $r->parts;
                $channels['sms']['parts'] += (int) $r->parts;
            }
        }
        foreach ($months as &$m) {
            $done = $m['sent'] + $m['failed'];
            $m['success_rate'] = $done > 0 ? round($m['sent'] * 100 / $done, 1) : null;
            $m['cost'] = $unit !== null ? round($m['sms_parts'] * $unit, 2) : null;
        }
        unset($m);
        foreach ($channels as $k => &$c) {
            $done = $c['sent'] + $c['failed'];
            $c['success_rate'] = $done > 0 ? round($c['sent'] * 100 / $done, 1) : null;
            $c['delivery_rate'] = $c['sent'] > 0 && $k !== 'email' ? round($c['delivered'] * 100 / $c['sent'], 1) : null;
        }
        unset($c);

        // Kaynak: toplu gönderim / otomasyon / duyuru / elle
        $bySource = (clone $base)->selectRaw("case when campaign_id is not null then 'campaign' when `trigger` like 'automation:%' then 'automation' when `trigger` like 'announcement:%' then 'announcement' else 'manual' end src, count(*) c, sum({$ok}) sent")
            ->groupBy('src')->get()->map(fn ($r) => ['key' => $r->src, 'total' => (int) $r->c, 'sent' => (int) $r->sent])->values();

        $campaigns = MessageCampaign::query()->whereNotNull('approved_at')->whereBetween('approved_at', [$from, $to])
            ->orderByDesc('approved_at')->limit(15)->get();
        $campaignCounts = DB::table('message_campaign_recipients')->whereIn('campaign_id', $campaigns->pluck('id'))
            ->selectRaw("campaign_id, sum(status in ('sent','delivered')) sent, sum(status='delivered') delivered, sum(status='failed') failed, sum(status='skipped') skipped, coalesce(sum(case when status in ('sent','delivered') then sms_parts else 0 end),0) parts")
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');

        $totalSent = array_sum(array_column($channels, 'sent'));
        $totalDone = $totalSent + array_sum(array_column($channels, 'failed'));

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'unit_price' => $unit,
            'totals' => [
                'sent' => $totalSent,
                'failed' => array_sum(array_column($channels, 'failed')),
                'success_rate' => $totalDone > 0 ? round($totalSent * 100 / $totalDone, 1) : null,
                'sms_parts' => $channels['sms']['parts'],
                'cost' => $unit !== null ? round($channels['sms']['parts'] * $unit, 2) : null,
                'campaigns' => $campaigns->count(),
            ],
            'channels' => $channels,
            'months' => array_values($months),
            'by_source' => $bySource,
            'campaigns' => $campaigns->map(fn (MessageCampaign $c) => [
                'id' => $c->id, 'name' => $c->name, 'channels' => $c->channels, 'is_commercial' => (bool) $c->is_commercial,
                'status' => $c->status, 'status_label' => MessageCampaign::STATUSES[$c->status] ?? $c->status,
                'approved_at' => $c->approved_at,
                'sent' => (int) ($campaignCounts[$c->id]->sent ?? 0),
                'delivered' => (int) ($campaignCounts[$c->id]->delivered ?? 0),
                'failed' => (int) ($campaignCounts[$c->id]->failed ?? 0),
                'skipped' => (int) ($campaignCounts[$c->id]->skipped ?? 0),
                'sms_parts' => (int) ($campaignCounts[$c->id]->parts ?? 0),
                'cost' => $unit !== null ? round((int) ($campaignCounts[$c->id]->parts ?? 0) * $unit, 2) : null,
            ])->values(),
        ]]);
    }

    private static function emptyChannel(): array
    {
        return ['total' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'parts' => 0, 'success_rate' => null, 'delivery_rate' => null];
    }

    private function unitPrice(int $branchId): ?float
    {
        $value = CampaignChannels::integration('sms', $branchId)?->config()['unit_price'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $v = (float) str_replace(',', '.', (string) $value);

        return $v > 0 ? $v : null;
    }
}
