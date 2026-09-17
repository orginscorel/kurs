<?php

namespace App\Services\Crm;

use App\Models\Lead;
use App\Support\Crm\LeadStages;
use Illuminate\Support\Facades\DB;

/**
 * CRM raporu: kaynak/aşama/sorumlu kırılımlı aday istatistikleri. Salt okunur toplamalar.
 */
class CrmReportService
{
    public function report(?string $from, ?string $to, ?string $source = null): array
    {
        $base = fn () => Lead::query()->when($from, fn ($q) => $q->whereDate('leads.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('leads.created_at', '<=', $to))
            ->when($source, fn ($q) => $q->where('leads.source', $source));

        $bySource = $base()->selectRaw('source, COUNT(*) AS total, SUM(stage = "won") AS won')
            ->groupBy('source')->get()
            ->map(fn ($r) => ['source' => $r->source, 'label' => Lead::SOURCES[$r->source] ?? $r->source, ...LeadStages::conversionRate((int) $r->won, (int) $r->total)])
            ->sortByDesc('total')->values();

        $funnel = collect(Lead::STAGES)->map(fn ($label, $stage) => [
            'stage' => $stage, 'label' => $label, 'count' => (clone $base())->where('stage', $stage)->count(),
        ])->values();

        $byOwner = $base()->whereNotNull('owner_id')->join('users', 'users.id', '=', 'leads.owner_id')
            ->selectRaw('users.id, users.name, COUNT(*) AS total, SUM(leads.stage = "won") AS won, SUM(leads.stage = "lost") AS lost')
            ->groupBy('users.id', 'users.name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'lost' => (int) $r->lost, ...LeadStages::conversionRate((int) $r->won, (int) $r->total)])
            ->sortByDesc('total')->values();

        $lostReasons = $base()->where('stage', 'lost')->whereNotNull('lost_reason')->where('lost_reason', '!=', '')
            ->selectRaw('lost_reason, COUNT(*) AS total')->groupBy('lost_reason')->orderByDesc('total')->limit(10)->get();

        $monthly = $base()->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total, SUM(stage = 'won') AS won")
            ->groupBy('ym')->orderBy('ym')->get();

        $totalLeads = $base()->count();
        $totalWon = (clone $base())->where('stage', 'won')->count();

        return [
            'summary' => LeadStages::conversionRate($totalWon, $totalLeads),
            'by_source' => $bySource,
            'funnel' => $funnel,
            'by_owner' => $byOwner,
            'lost_reasons' => $lostReasons,
            'monthly_trend' => $monthly,
        ];
    }
}
