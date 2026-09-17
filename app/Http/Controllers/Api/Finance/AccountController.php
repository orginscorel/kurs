<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\AccountTransfer;
use App\Models\FinanceAccount;
use App\Models\Payment;
use App\Services\Finance\AccountService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends FinanceController
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $today = CarbonImmutable::today();
        $flows = DB::table('account_transactions')->where('branch_id', $this->branchId())->where('occurred_at', '>=', $today->startOfDay())
            ->groupBy('finance_account_id')
            ->selectRaw('finance_account_id, COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflow, COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS outflow, COUNT(*) AS c')
            ->get()->keyBy('finance_account_id');
        $lastMove = DB::table('account_transactions')->where('branch_id', $this->branchId())->groupBy('finance_account_id')
            ->selectRaw('finance_account_id, MAX(occurred_at) AS last_at, COUNT(*) AS total')->get()->keyBy('finance_account_id');

        $rows = FinanceAccount::query()->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_active')->orderByRaw("FIELD(kind, 'cash', 'pos', 'bank')")->orderBy('name')->get()
            ->map(fn (FinanceAccount $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'kind_label' => FinanceAccount::KINDS[$a->kind] ?? $a->kind, 'name' => $a->name, 'bank_name' => $a->bank_name, 'iban' => $a->iban,
                'balance' => (string) $a->balance, 'opening_balance' => (string) $a->opening_balance, 'is_active' => $a->is_active,
                'today_inflow' => bcadd((string) ($flows[$a->id]->inflow ?? '0'), '0', 2), 'today_outflow' => bcadd((string) ($flows[$a->id]->outflow ?? '0'), '0', 2),
                'today_count' => (int) ($flows[$a->id]->c ?? 0), 'last_movement_at' => $lastMove[$a->id]->last_at ?? null, 'transaction_count' => (int) ($lastMove[$a->id]->total ?? 0),
            ]);

        $active = $rows->where('is_active', true);

        return response()->json([
            'data' => $rows->values(),
            'totals' => [
                'balance' => $active->reduce(fn ($s, $r) => bcadd($s, $r['balance'], 2), '0.00'),
                'by_kind' => collect(FinanceAccount::KINDS)->map(fn ($label, $kind) => ['kind' => $kind, 'label' => $label, 'balance' => $active->where('kind', $kind)->reduce(fn ($s, $r) => bcadd($s, $r['balance'], 2), '0.00')])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'kind' => ['required', Rule::in(array_keys(FinanceAccount::KINDS))],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'iban' => ['nullable', 'string', 'max:40'],
            'opening_balance' => ['nullable', 'string', self::MONEY_SIGNED],
        ]);
        $account = $this->accounts->create($data);

        return response()->json(['message' => 'Hesap oluşturuldu.', 'id' => $account->id], 201);
    }

    public function update(Request $request, FinanceAccount $account): JsonResponse
    {
        $data = $this->validateTr($request, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'iban' => ['nullable', 'string', 'max:40'],
            'is_active' => ['boolean'],
        ]);
        $this->accounts->update($account, $data);

        return $this->ok('Hesap güncellendi.');
    }

    public function destroy(FinanceAccount $account): JsonResponse
    {
        $this->accounts->delete($account);

        return $this->ok('Hesap silindi.');
    }

    /** Hesap defteri: hareketler + dönem başı/sonu bakiyesi. */
    public function ledger(Request $request, FinanceAccount $account): JsonResponse
    {
        $from = $request->date('from') ? CarbonImmutable::parse($request->date('from'))->startOfDay() : null;
        $to = $request->date('to') ? CarbonImmutable::parse($request->date('to'))->endOfDay() : null;

        $base = DB::table('account_transactions as t')->where('t.finance_account_id', $account->id);
        $opening = $from ? (string) (clone $base)->where('t.occurred_at', '<', $from)->sum('t.amount') : '0';
        $period = (clone $base)->when($from, fn ($q) => $q->where('t.occurred_at', '>=', $from))->when($to, fn ($q) => $q->where('t.occurred_at', '<=', $to))
            ->when($request->query('direction') === 'in', fn ($q) => $q->where('t.amount', '>', 0))
            ->when($request->query('direction') === 'out', fn ($q) => $q->where('t.amount', '<', 0));
        $sum = (clone $period)->selectRaw('COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS inflow, COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END), 0) AS outflow')->first();
        $ledgerTotal = (string) (clone $base)->sum('t.amount');

        $page = $period->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->leftJoin('payments as p', fn ($j) => $j->on('p.id', '=', 't.source_id')->where('t.source_type', 'payment'))
            ->orderByDesc('t.occurred_at')->orderByDesc('t.id')
            ->paginate($this->perPage($request, 50), ['t.id', 't.amount', 't.balance_after', 't.source_type', 't.source_id', 't.description', 't.occurred_at', 't.created_at', 'u.name as user', 'p.student_id', 'p.receipt_no']);

        $opening = bcadd($opening, '0', 2);
        $inflow = bcadd((string) $sum->inflow, '0', 2);
        $outflow = bcadd((string) $sum->outflow, '0', 2);

        return $this->paginated($page, fn ($t) => [
            'id' => $t->id, 'amount' => bcadd((string) $t->amount, '0', 2), 'balance_after' => bcadd((string) $t->balance_after, '0', 2),
            'source_type' => $t->source_type, 'source_id' => $t->source_id, 'description' => $t->description, 'occurred_at' => $t->occurred_at,
            'created_at' => $t->created_at, 'user' => $t->user, 'student_id' => $t->student_id, 'receipt_no' => $t->receipt_no,
        ], [
            'account' => ['id' => $account->id, 'name' => $account->name, 'kind' => $account->kind, 'kind_label' => FinanceAccount::KINDS[$account->kind] ?? $account->kind,
                'balance' => (string) $account->balance, 'is_active' => $account->is_active, 'bank_name' => $account->bank_name, 'iban' => $account->iban],
            'summary' => ['opening' => $opening, 'inflow' => $inflow, 'outflow' => $outflow, 'closing' => bcsub(bcadd($opening, $inflow, 2), $outflow, 2)],
            // Tutarlılık göstergesi: hareket toplamı önbellek bakiyeye eşit olmalı.
            'ledger_balance' => bcadd($ledgerTotal, '0', 2),
            'balance_consistent' => bccomp(bcadd($ledgerTotal, '0', 2), (string) $account->balance, 2) === 0,
        ]);
    }

    public function transfers(Request $request): JsonResponse
    {
        $query = AccountTransfer::query()->with(['from:id,name', 'to:id,name'])
            ->when(($kind = $request->query('kind')) && isset(AccountService::TRANSFER_KINDS[$kind]), fn ($q) => $q->where('kind', $kind))
            ->when($request->integer('account_id'), fn ($q, $id) => $q->where(fn ($w) => $w->where('from_account_id', $id)->orWhere('to_account_id', $id)))
            ->orderByDesc('transfer_date')->orderByDesc('id');
        if ($from = $request->date('from')) {
            $query->where('transfer_date', '>=', $from->toDateString());
        }
        if ($to = $request->date('to')) {
            $query->where('transfer_date', '<=', $to->toDateString());
        }
        $users = DB::table('users')->pluck('name', 'id');

        return $this->paginated($query->paginate($this->perPage($request, 25)), fn (AccountTransfer $t) => [
            'id' => $t->id, 'kind' => $t->kind, 'kind_label' => AccountService::TRANSFER_KINDS[$t->kind] ?? $t->kind,
            'from' => $t->from ? ['id' => $t->from->id, 'name' => $t->from->name] : null, 'to' => $t->to ? ['id' => $t->to->id, 'name' => $t->to->name] : null,
            'amount' => (string) $t->amount, 'transfer_date' => $t->transfer_date->toDateString(), 'description' => $t->description,
            'created_by' => $users[$t->created_by] ?? null, 'voided_at' => $t->voided_at?->toAtomString(), 'void_reason' => $t->void_reason,
        ]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'from_account_id' => ['required', 'integer'],
            'to_account_id' => ['required', 'integer', 'different:from_account_id'],
            'amount' => ['required', 'string', self::MONEY],
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:300'],
        ], ['to_account_id.different' => 'Kaynak ve hedef hesap aynı olamaz.', 'amount.regex' => 'Tutarı 1500 ya da 1500,50 biçiminde girin.']);

        $transfer = $this->accounts->transfer((int) $data['from_account_id'], (int) $data['to_account_id'], $data['amount'], $data['transfer_date'], $data['description'] ?? null);

        return response()->json(['message' => 'Transfer kaydedildi.', 'id' => $transfer->id], 201);
    }

    public function voidTransfer(Request $request, AccountTransfer $transfer): JsonResponse
    {
        $data = $this->validateTr($request, ['reason' => ['required', 'string', 'min:5', 'max:300']], ['reason.min' => 'İptal gerekçesi en az 5 karakter olmalı.']);
        $this->accounts->voidTransfer($transfer, $data['reason']);

        return $this->ok('Kayıt iptal edildi; bakiyeler ters kayıtla düzeltildi.');
    }

    public function adjust(Request $request, FinanceAccount $account): JsonResponse
    {
        $data = $this->validateTr($request, [
            'counted_balance' => ['required', 'string', self::MONEY_SIGNED],
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ]);
        $this->accounts->adjust($account, $data['counted_balance'], $data['reason']);

        return $this->ok('Sayım farkı kaydedildi.');
    }

    /** Gün sonu kasa özeti: hesap başına devir, giriş, çıkış, kapanış; tahsilatların yönteme göre dağılımı. */
    public function dayEnd(Request $request): JsonResponse
    {
        $day = $request->date('date') ? CarbonImmutable::parse($request->date('date')) : CarbonImmutable::today();
        $start = $day->startOfDay();
        $end = $day->endOfDay();
        $branchId = $this->branchId();

        $accounts = FinanceAccount::query()->where('is_active', true)->orderByRaw("FIELD(kind, 'cash', 'pos', 'bank')")->orderBy('name')->get();
        $before = DB::table('account_transactions')->where('branch_id', $branchId)->where('occurred_at', '<', $start)->groupBy('finance_account_id')->selectRaw('finance_account_id, SUM(amount) AS s')->pluck('s', 'finance_account_id');
        $during = DB::table('account_transactions')->where('branch_id', $branchId)->whereBetween('occurred_at', [$start, $end])->groupBy('finance_account_id', 'source_type')
            ->selectRaw('finance_account_id, source_type, COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS inflow, COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS outflow, COUNT(*) AS c')->get();

        $rows = $accounts->map(function (FinanceAccount $a) use ($before, $during) {
            $opening = bcadd((string) ($before[$a->id] ?? '0'), '0', 2);
            $mine = $during->where('finance_account_id', $a->id);
            $in = $mine->reduce(fn ($s, $r) => bcadd($s, (string) $r->inflow, 2), '0.00');
            $out = $mine->reduce(fn ($s, $r) => bcadd($s, (string) $r->outflow, 2), '0.00');

            return [
                'id' => $a->id, 'name' => $a->name, 'kind' => $a->kind, 'kind_label' => FinanceAccount::KINDS[$a->kind] ?? $a->kind,
                'opening' => $opening, 'inflow' => $in, 'outflow' => $out, 'closing' => bcsub(bcadd($opening, $in, 2), $out, 2), 'current_balance' => (string) $a->balance,
                'by_source' => $mine->map(fn ($r) => ['source_type' => $r->source_type, 'inflow' => bcadd((string) $r->inflow, '0', 2), 'outflow' => bcadd((string) $r->outflow, '0', 2), 'count' => (int) $r->c])->values(),
            ];
        });

        $methods = DB::table('payments')->where('branch_id', $branchId)->whereNull('voided_at')->whereBetween('paid_at', [$start, $end])
            ->groupBy('method')->selectRaw('method, COUNT(*) AS c, SUM(amount) AS s')->get()
            ->map(fn ($r) => ['method' => $r->method, 'label' => Payment::METHODS[$r->method] ?? $r->method, 'count' => (int) $r->c, 'amount' => bcadd((string) $r->s, '0', 2)]);

        $movements = DB::table('account_transactions as t')->join('finance_accounts as a', 'a.id', '=', 't.finance_account_id')->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.branch_id', $branchId)->whereBetween('t.occurred_at', [$start, $end])->orderBy('t.occurred_at')->orderBy('t.id')->limit(500)
            ->get(['t.id', 't.amount', 't.description', 't.occurred_at', 't.source_type', 'a.name as account', 'a.kind', 'u.name as user']);

        $sum = fn (string $k) => $rows->reduce(fn ($s, $r) => bcadd($s, $r[$k], 2), '0.00');

        return response()->json(['data' => [
            'date' => $day->toDateString(),
            'accounts' => $rows->values(),
            'totals' => ['opening' => $sum('opening'), 'inflow' => $sum('inflow'), 'outflow' => $sum('outflow'), 'closing' => $sum('closing')],
            'payments_by_method' => $methods->values(),
            'voided_today' => (int) DB::table('payments')->where('branch_id', $branchId)->whereBetween('voided_at', [$start, $end])->count(),
            'movements' => $movements->map(fn ($m) => [...(array) $m, 'amount' => bcadd((string) $m->amount, '0', 2)]),
        ]]);
    }
}
