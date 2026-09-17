<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Muhasebe raporları (yevmiye fişlerinden): yevmiye defteri, büyük defter / muavin, mizan, hesap bakiyeleri.
 */
class LedgerReports
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /**
     * Mizan: dönem başı devir, dönem borç/alacak, kapanış bakiyesi (borç ya da alacak kalanı).
     *
     * @return array{rows: list<array>, totals: array<string,string>, balanced: bool}
     */
    public function trialBalance(int $branchId, CarbonImmutable $from, CarbonImmutable $to, bool $mainOnly = false): array
    {
        $accounts = $this->chart->accounts($branchId);
        $codeExpr = $mainOnly ? 'SUBSTR(ledger_code, 1, 3)' : 'ledger_code';
        $agg = DB::table('journal_lines')->where('branch_id', $branchId)->where('entry_date', '<=', $to->toDateString())
            ->groupBy('code')
            ->selectRaw("{$codeExpr} AS code,
                COALESCE(SUM(CASE WHEN entry_date < ? THEN debit ELSE 0 END), 0) AS od,
                COALESCE(SUM(CASE WHEN entry_date < ? THEN credit ELSE 0 END), 0) AS oc,
                COALESCE(SUM(CASE WHEN entry_date >= ? THEN debit ELSE 0 END), 0) AS pd,
                COALESCE(SUM(CASE WHEN entry_date >= ? THEN credit ELSE 0 END), 0) AS pc", array_fill(0, 4, $from->toDateString()))
            ->orderBy('code')->get();

        $t = ['opening_debit' => '0.00', 'opening_credit' => '0.00', 'period_debit' => '0.00', 'period_credit' => '0.00', 'total_debit' => '0.00', 'total_credit' => '0.00', 'closing_debit' => '0.00', 'closing_credit' => '0.00'];
        $rows = [];
        foreach ($agg as $r) {
            $od = Dec::round(Dec::norm($r->od));
            $oc = Dec::round(Dec::norm($r->oc));
            $pd = Dec::round(Dec::norm($r->pd));
            $pc = Dec::round(Dec::norm($r->pc));
            $openNet = bcsub($od, $oc, 2);
            $td = bcadd($od, $pd, 2);
            $tc = bcadd($oc, $pc, 2);
            $net = bcsub($td, $tc, 2);
            $row = [
                'code' => $r->code,
                'name' => $accounts[$r->code]['name'] ?? ($accounts[substr($r->code, 0, 3)]['name'] ?? '—'),
                'type' => $accounts[$r->code]['type'] ?? ($accounts[substr($r->code, 0, 3)]['type'] ?? null),
                'opening_debit' => bccomp($openNet, '0', 2) > 0 ? $openNet : '0.00',
                'opening_credit' => bccomp($openNet, '0', 2) < 0 ? bcmul($openNet, '-1', 2) : '0.00',
                'period_debit' => $pd,
                'period_credit' => $pc,
                'total_debit' => $td,
                'total_credit' => $tc,
                'closing_debit' => bccomp($net, '0', 2) > 0 ? $net : '0.00',
                'closing_credit' => bccomp($net, '0', 2) < 0 ? bcmul($net, '-1', 2) : '0.00',
            ];
            if (Dec::isZero($td) && Dec::isZero($tc)) {
                continue;
            }
            foreach ($t as $k => $v) {
                $t[$k] = bcadd($v, $row[$k], 2);
            }
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'totals' => $t,
            'balanced' => bccomp($t['total_debit'], $t['total_credit'], 2) === 0 && bccomp($t['closing_debit'], $t['closing_credit'], 2) === 0,
        ];
    }

    /**
     * Büyük defter / muavin: bir hesabın (isteğe bağlı cari: öğrenci/veli) hareketleri ve yürüyen bakiye.
     *
     * @return array{account: ?array, opening: string, rows: list<array>, totals: array{debit:string, credit:string, closing:string}, partners: list<array>}
     */
    public function generalLedger(int $branchId, string $code, CarbonImmutable $from, CarbonImmutable $to, ?string $partnerType = null, ?int $partnerId = null, int $limit = 2000): array
    {
        $accounts = $this->chart->accounts($branchId);
        $base = DB::table('journal_lines as l')->where('l.branch_id', $branchId)
            ->where(fn ($q) => $q->where('l.ledger_code', $code)->orWhere('l.ledger_code', 'like', $code.'.%'))
            ->when($partnerType, fn ($q) => $q->where('l.partner_type', $partnerType)->where('l.partner_id', $partnerId));

        $open = (clone $base)->where('l.entry_date', '<', $from->toDateString())->selectRaw('COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c')->first();
        $opening = bcsub(Dec::round(Dec::norm($open->d)), Dec::round(Dec::norm($open->c)), 2);

        $lines = (clone $base)->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereBetween('l.entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('l.entry_date')->orderBy('l.journal_entry_id')->orderBy('l.id')->limit($limit)
            ->get(['l.id', 'l.entry_date', 'l.ledger_code', 'l.debit', 'l.credit', 'l.description', 'l.partner_type', 'l.partner_id', 'l.partner_name',
                'e.id as entry_id', 'e.entry_no', 'e.source_event', 'e.source_type', 'e.source_id']);

        $balance = $opening;
        $d = '0.00';
        $c = '0.00';
        $rows = [];
        foreach ($lines as $l) {
            $ld = Dec::round(Dec::norm($l->debit));
            $lc = Dec::round(Dec::norm($l->credit));
            $balance = bcadd($balance, bcsub($ld, $lc, 2), 2);
            $d = bcadd($d, $ld, 2);
            $c = bcadd($c, $lc, 2);
            $rows[] = [
                'id' => $l->id, 'date' => substr((string) $l->entry_date, 0, 10), 'entry_id' => $l->entry_id, 'entry_no' => $l->entry_no,
                'event' => $l->source_event, 'source_type' => $l->source_type, 'source_id' => $l->source_id, 'code' => $l->ledger_code,
                'description' => $l->description, 'partner' => $l->partner_name, 'partner_type' => $l->partner_type, 'partner_id' => $l->partner_id,
                'debit' => $ld, 'credit' => $lc, 'balance' => $balance,
            ];
        }

        // Muavin özeti: bu hesapta en yüksek bakiyeli cariler
        $partners = $partnerType ? [] : (clone $base)->whereNotNull('l.partner_id')->where('l.entry_date', '<=', $to->toDateString())
            ->groupBy('l.partner_type', 'l.partner_id')
            ->selectRaw('l.partner_type, l.partner_id, MAX(l.partner_name) AS name, SUM(l.debit) AS d, SUM(l.credit) AS c')
            ->havingRaw('SUM(l.debit) <> SUM(l.credit)')->orderByRaw('ABS(SUM(l.debit) - SUM(l.credit)) DESC')->limit(50)->get()
            ->map(fn ($p) => ['partner_type' => $p->partner_type, 'partner_id' => (int) $p->partner_id, 'name' => $p->name,
                'balance' => bcsub(Dec::round(Dec::norm($p->d)), Dec::round(Dec::norm($p->c)), 2)])->all();

        return [
            'account' => $accounts[$code] ?? null,
            'opening' => $opening,
            'rows' => $rows,
            'truncated' => count($rows) >= $limit,
            'totals' => ['debit' => $d, 'credit' => $c, 'closing' => $balance],
            'partners' => $partners,
        ];
    }

    /** Hesap planı + bakiye (bugüne kadar). */
    public function balances(int $branchId): array
    {
        $sums = DB::table('journal_lines')->where('branch_id', $branchId)->groupBy('ledger_code')
            ->selectRaw('ledger_code, SUM(debit) AS d, SUM(credit) AS c, COUNT(*) AS n')->get()->keyBy('ledger_code');

        return collect($this->chart->accounts($branchId))->map(function ($a) use ($sums) {
            $s = $sums[$a['code']] ?? null;
            $net = $s ? bcsub(Dec::round(Dec::norm($s->d)), Dec::round(Dec::norm($s->c)), 2) : '0.00';

            return $a + ['balance' => $net, 'line_count' => (int) ($s->n ?? 0)];
        })->values()->all();
    }

    /**
     * Doğrulama: her fiş dengeli mi, satır toplamı başlıkla aynı mı, kasa/banka hesap kodları bakiyesi
     * finans hesaplarının bakiyesiyle tutuyor mu?
     *
     * @return array{entries:int, unbalanced: list<string>, header_mismatch: list<string>, accounts: list<array>}
     */
    public function verify(int $branchId): array
    {
        $bad = DB::table('journal_entries')->where('branch_id', $branchId)->whereColumn('total_debit', '!=', 'total_credit')->pluck('entry_no')->all();
        $mismatch = DB::table('journal_entries as e')
            ->joinSub(DB::table('journal_lines')->groupBy('journal_entry_id')->selectRaw('journal_entry_id, SUM(debit) AS d, SUM(credit) AS c'), 's', 's.journal_entry_id', '=', 'e.id')
            ->where('e.branch_id', $branchId)
            ->where(fn ($q) => $q->whereColumn('s.d', '!=', 'e.total_debit')->orWhereColumn('s.c', '!=', 'e.total_credit')->orWhereColumn('s.d', '!=', 's.c'))
            ->pluck('e.entry_no')->all();

        $accounts = [];
        foreach (DB::table('finance_accounts')->where('branch_id', $branchId)->get(['id', 'name', 'balance']) as $a) {
            $s = DB::table('journal_lines')->where('branch_id', $branchId)->where('finance_account_id', $a->id)->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();
            $ledger = bcsub(Dec::round(Dec::norm($s->d)), Dec::round(Dec::norm($s->c)), 2);
            $balance = Dec::round(Dec::norm($a->balance));
            $accounts[] = ['id' => $a->id, 'name' => $a->name, 'balance' => $balance, 'ledger' => $ledger, 'ok' => bccomp($balance, $ledger, 2) === 0];
        }

        return [
            'entries' => DB::table('journal_entries')->where('branch_id', $branchId)->count(),
            'unbalanced' => $bad,
            'header_mismatch' => $mismatch,
            'accounts' => $accounts,
        ];
    }
}
