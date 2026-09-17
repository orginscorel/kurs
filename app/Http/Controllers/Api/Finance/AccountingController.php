<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\LedgerReports;
use App\Services\Accounting\PeriodLock;
use App\Services\Finance\FinanceAudit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Muhasebe: yevmiye, büyük defter/muavin, mizan, hesap planı + eşleme, dönem kilidi. */
class AccountingController extends FinanceController
{
    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerReports $reports,
        private readonly PeriodLock $periods,
        private readonly JournalService $journal,
    ) {}

    public function journal(Request $request): JsonResponse
    {
        $q = $this->journalQuery($request);
        $totals = (clone $q)->reorder()->select([])->selectRaw('COUNT(*) AS c, COALESCE(SUM(total_debit), 0) AS d')->first();
        $page = $q->orderByDesc('entry_date')->orderByDesc('id')->paginate($this->perPage($request, 30));
        $lines = DB::table('journal_lines')->whereIn('journal_entry_id', collect($page->items())->pluck('id'))->orderBy('id')->get()->groupBy('journal_entry_id');
        $names = $this->chart->accounts($this->branchId());

        return $this->paginated($page, fn (JournalEntry $e) => $this->entryRow($e, $lines[$e->id] ?? collect(), $names), [
            'totals' => ['count' => (int) $totals->c, 'amount' => bcadd((string) $totals->d, '0', 2)],
            'events' => JournalEntry::EVENTS,
        ]);
    }

    public function journalExport(Request $request): StreamedResponse
    {
        $q = $this->journalQuery($request)->orderBy('entry_date')->orderBy('id');
        $names = $this->chart->accounts($this->branchId());
        FinanceAudit::log('accounting.journal_exported', 'yevmiye defterini (fiş listesi) Excel olarak dışa aktardı.');

        return $this->xlsx('yevmiye-'.now()->format('Y-m-d').'.xlsx',
            ['Fiş no', 'Tarih', 'İşlem', 'Fiş açıklaması', 'Hesap kodu', 'Hesap adı', 'Satır açıklaması', 'Cari', 'Borç', 'Alacak'],
            function (callable $add) use ($q, $names) {
                $q->chunkById(300, function ($chunk) use ($add, $names) {
                    $lines = DB::table('journal_lines')->whereIn('journal_entry_id', $chunk->pluck('id'))->orderBy('id')->get()->groupBy('journal_entry_id');
                    foreach ($chunk as $e) {
                        foreach ($lines[$e->id] ?? [] as $l) {
                            $add([$e->entry_no, $e->entry_date->format('d.m.Y'), JournalEntry::EVENTS[$e->source_event] ?? $e->source_event, $e->description,
                                $l->ledger_code, $names[$l->ledger_code]['name'] ?? '', $l->description ?? '', $l->partner_name ?? '', $this->cell($l->debit), $this->cell($l->credit)]);
                        }
                    }
                });
            });
    }

    public function entry(JournalEntry $entry): JsonResponse
    {
        $names = $this->chart->accounts($this->branchId());
        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->orderBy('id')->get();
        $row = $this->entryRow($entry, $lines, $names);
        $row['reversal'] = JournalEntry::query()->where('reversal_of_id', $entry->id)->first(['id', 'entry_no', 'entry_date']);
        $row['reverses'] = $entry->reversal_of_id ? JournalEntry::query()->whereKey($entry->reversal_of_id)->first(['id', 'entry_no', 'entry_date']) : null;
        $row['created_by'] = $entry->created_by ? DB::table('users')->where('id', $entry->created_by)->value('name') : null;

        return response()->json(['data' => $row]);
    }

    /** Elle fiş (muhasebeci düzeltmesi): satırlar dengeli olmalı. */
    public function storeManual(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'min:3', 'max:300'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.code' => ['required', 'string', 'max:20'],
            'lines.*.debit' => ['nullable', 'string', self::MONEY],
            'lines.*.credit' => ['nullable', 'string', self::MONEY],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
        ]);
        $lines = array_map(fn ($l) => ['code' => $l['code'], 'debit' => Money::of($l['debit'] ?? '0'), 'credit' => Money::of($l['credit'] ?? '0'), 'description' => $l['description'] ?? null], $data['lines']);
        $entry = DB::transaction(fn () => $this->journal->post($this->branchId(), $data['entry_date'], $data['description'], null, null, 'manual', $lines));
        FinanceAudit::log('accounting.manual_entry', sprintf('%s numaralı elle yevmiye fişi kesti (%s TL): %s', $entry->entry_no, Money::format($entry->total_debit), $entry->description));

        return response()->json(['message' => "{$entry->entry_no} numaralı fiş kaydedildi.", 'id' => $entry->id], 201);
    }

    public function reverseManual(Request $request, JournalEntry $entry): JsonResponse
    {
        $data = $this->validateTr($request, ['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($entry->source_event !== 'manual' || $entry->reversed_at || $entry->reversal_of_id) {
            return response()->json(['message' => 'Yalnız ters kaydı yapılmamış elle fişler buradan ters çevrilir; otomatik fişler kaynak belge iptal edilince kapanır.'], 422);
        }
        $rev = DB::transaction(function () use ($entry, $data) {
            $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->get()
                ->map(fn ($l) => ['code' => $l->ledger_code, 'debit' => (string) $l->credit, 'credit' => (string) $l->debit, 'description' => 'Ters kayıt: '.($l->description ?? '')])->all();
            $rev = $this->journal->post($entry->branch_id, CarbonImmutable::today(), 'Ters kayıt: '.$entry->entry_no.' — '.$data['reason'], null, null, 'manual', $lines, $entry->id);
            JournalEntry::query()->whereKey($entry->id)->first()->forceFill(['reversed_at' => now()])->save();

            return $rev;
        });
        FinanceAudit::log('accounting.manual_reversed', "{$entry->entry_no} numaralı elle fişi {$rev->entry_no} ile ters çevirdi. Gerekçe: {$data['reason']}");

        return $this->ok("{$rev->entry_no} ters fişi kesildi.");
    }

    public function ledger(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $this->validateTr($request, ['code' => ['required', 'string', 'max:20'], 'partner_type' => ['nullable', 'in:student,guardian,other'], 'partner_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->reports->generalLedger($this->branchId(), (string) $request->query('code'), $from, $to,
            $request->query('partner_type'), $request->integer('partner_id') ?: null)]);
    }

    public function ledgerExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $code = (string) $this->validateTr($request, ['code' => ['required', 'string', 'max:20']])['code'];
        $r = $this->reports->generalLedger($this->branchId(), $code, $from, $to, $request->query('partner_type'), $request->integer('partner_id') ?: null, 50000);
        FinanceAudit::log('accounting.ledger_exported', "{$code} hesabının büyük defterini Excel olarak dışa aktardı.");

        return $this->xlsx("buyuk-defter-{$code}-{$from->toDateString()}-{$to->toDateString()}.xlsx", ['Tarih', 'Fiş no', 'Hesap', 'Açıklama', 'Cari', 'Borç', 'Alacak', 'Bakiye'],
            function (callable $add) use ($r, $from) {
                $add([$from->format('d.m.Y'), '', '', 'Devir', '', '', '', $this->cell($r['opening'])]);
                foreach ($r['rows'] as $l) {
                    $add([CarbonImmutable::parse($l['date'])->format('d.m.Y'), $l['entry_no'], $l['code'], $l['description'] ?? '', $l['partner'] ?? '', $this->cell($l['debit']), $this->cell($l['credit']), $this->cell($l['balance'])]);
                }
                $add(['', '', '', 'Toplam', '', $this->cell($r['totals']['debit']), $this->cell($r['totals']['credit']), $this->cell($r['totals']['closing'])]);
            });
    }

    public function trialBalance(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->reports->trialBalance($this->branchId(), $from, $to, $request->boolean('main_only')) + ['from' => $from->toDateString(), 'to' => $to->toDateString()]]);
    }

    public function trialBalanceExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $r = $this->reports->trialBalance($this->branchId(), $from, $to, $request->boolean('main_only'));
        FinanceAudit::log('accounting.trial_balance_exported', sprintf('%s – %s mizanını Excel olarak dışa aktardı.', $from->format('d.m.Y'), $to->format('d.m.Y')));

        return $this->xlsx("mizan-{$from->toDateString()}-{$to->toDateString()}.xlsx",
            ['Hesap kodu', 'Hesap adı', 'Devir borç', 'Devir alacak', 'Dönem borç', 'Dönem alacak', 'Toplam borç', 'Toplam alacak', 'Borç bakiye', 'Alacak bakiye'],
            function (callable $add) use ($r) {
                foreach ($r['rows'] as $x) {
                    $add([$x['code'], $x['name'], $this->cell($x['opening_debit']), $this->cell($x['opening_credit']), $this->cell($x['period_debit']), $this->cell($x['period_credit']),
                        $this->cell($x['total_debit']), $this->cell($x['total_credit']), $this->cell($x['closing_debit']), $this->cell($x['closing_credit'])]);
                }
                $t = $r['totals'];
                $add(['', 'TOPLAM', $this->cell($t['opening_debit']), $this->cell($t['opening_credit']), $this->cell($t['period_debit']), $this->cell($t['period_credit']),
                    $this->cell($t['total_debit']), $this->cell($t['total_credit']), $this->cell($t['closing_debit']), $this->cell($t['closing_credit'])]);
            });
    }

    public function chart(): JsonResponse
    {
        return response()->json(['data' => [
            'accounts' => $this->reports->balances($this->branchId()),
            'mappings' => $this->chart->mappingOverview($this->branchId()),
            'types' => LedgerAccount::TYPES,
        ]]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, ['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'min:2', 'max:160'], 'type' => ['required', Rule::in(array_keys(LedgerAccount::TYPES))]]);
        $a = $this->chart->saveAccount($this->branchId(), $data);

        return response()->json(['message' => "{$a->code} {$a->name} hesabı açıldı.", 'id' => $a->id], 201);
    }

    public function updateAccount(Request $request, LedgerAccount $account): JsonResponse
    {
        $data = $this->validateTr($request, ['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'min:2', 'max:160'],
            'type' => ['required', Rule::in(array_keys(LedgerAccount::TYPES))], 'is_active' => ['boolean']]);
        $this->chart->saveAccount($this->branchId(), $data, $account);

        return $this->ok('Hesap güncellendi.');
    }

    public function updateMapping(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, ['source_type' => ['required', Rule::in(['finance_account', 'finance_category', 'role'])], 'source_key' => ['required', 'string', 'max:60'], 'code' => ['nullable', 'string', 'max:20']]);
        $this->chart->setMapping($this->branchId(), $data['source_type'], $data['source_key'], $data['code'] ?: null);

        return $this->ok('Eşleme kaydedildi; yeni fişlerde kullanılacak.');
    }

    public function periods(): JsonResponse
    {
        $b = $this->branchId();
        $rows = AccountingPeriod::query()->get()->keyBy('period');
        $counts = DB::table('journal_entries')->where('branch_id', $b)->selectRaw('SUBSTR(entry_date, 1, 7) AS p, COUNT(*) AS c, SUM(total_debit) AS d')->groupBy('p')->get()->keyBy('p');
        $users = DB::table('users')->whereIn('id', $rows->pluck('closed_by')->merge($rows->pluck('reopened_by'))->filter()->unique())->pluck('name', 'id');
        $first = $counts->keys()->min() ?? CarbonImmutable::today()->format('Y-m');
        $list = [];
        for ($c = CarbonImmutable::parse($first.'-01'); $c->lte(CarbonImmutable::today()); $c = $c->addMonthNoOverflow()) {
            $p = $c->format('Y-m');
            $r = $rows[$p] ?? null;
            $list[] = [
                'period' => $p, 'label' => $c->locale('tr')->translatedFormat('F Y'), 'status' => $r?->status === 'closed' ? 'closed' : 'open',
                'ended' => $c->endOfMonth()->lt(CarbonImmutable::today()), 'entries' => (int) ($counts[$p]->c ?? 0), 'amount' => bcadd((string) ($counts[$p]->d ?? '0'), '0', 2),
                'closed_at' => $r?->closed_at?->toAtomString(), 'closed_by' => $users[$r?->closed_by] ?? null,
                'reopened_at' => $r?->reopened_at?->toAtomString(), 'reopened_by' => $users[$r?->reopened_by] ?? null, 'note' => $r?->note,
            ];
        }

        return response()->json(['data' => array_reverse($list)]);
    }

    public function closePeriod(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, ['period' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:300']]);
        $this->periods->close($this->branchId(), $data['period'], $data['note'] ?? null);

        return $this->ok('Dönem kapatıldı; bu aya artık kayıt atılamaz.');
    }

    public function reopenPeriod(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, ['period' => ['required', 'string'], 'reason' => ['required', 'string', 'min:5', 'max:250']]);
        $this->periods->reopen($this->branchId(), $data['period'], $data['reason']);

        return $this->ok('Dönem yeniden açıldı.');
    }

    public function verify(): JsonResponse
    {
        return response()->json(['data' => $this->reports->verify($this->branchId())]);
    }

    // ------------------------------------------------------------------

    private function journalQuery(Request $request)
    {
        [$from, $to] = $this->range($request);
        $q = JournalEntry::query()->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]);
        if ($event = $request->query('event')) {
            $q->where('source_event', $event);
        }
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('entry_no', 'like', strtoupper($s).'%')->orWhere('description', 'like', "%{$s}%"));
        }
        if ($code = $request->query('code')) {
            $q->whereExists(fn ($x) => $x->from('journal_lines as l')->whereColumn('l.journal_entry_id', 'journal_entries.id')->where('l.ledger_code', 'like', $code.'%'));
        }
        if ($request->query('source_type') && $request->integer('source_id')) {
            $q->where('source_type', $request->query('source_type'))->where('source_id', $request->integer('source_id'));
        }

        return $q;
    }

    private function entryRow(JournalEntry $e, $lines, array $names): array
    {
        return [
            'id' => $e->id, 'entry_no' => $e->entry_no, 'entry_date' => $e->entry_date->toDateString(), 'description' => $e->description,
            'event' => $e->source_event, 'event_label' => JournalEntry::EVENTS[$e->source_event] ?? $e->source_event,
            'source_type' => $e->source_type, 'source_id' => $e->source_id, 'total' => (string) $e->total_debit,
            'reversed' => $e->reversed_at !== null, 'is_reversal' => $e->reversal_of_id !== null,
            'lines' => collect($lines)->map(fn ($l) => [
                'code' => $l->ledger_code, 'name' => $names[$l->ledger_code]['name'] ?? '', 'debit' => bcadd((string) $l->debit, '0', 2), 'credit' => bcadd((string) $l->credit, '0', 2),
                'description' => $l->description, 'partner' => $l->partner_name, 'partner_type' => $l->partner_type, 'partner_id' => $l->partner_id,
            ])->values(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $this->validateTr($request, ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $today = CarbonImmutable::today();

        return [
            $request->query('from') ? CarbonImmutable::parse($request->query('from')) : $today->startOfMonth(),
            $request->query('to') ? CarbonImmutable::parse($request->query('to')) : $today,
        ];
    }
}
