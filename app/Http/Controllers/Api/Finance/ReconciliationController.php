<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\FinanceAccount;
use App\Models\Payment;
use App\Models\PosSettlement;
use App\Services\Finance\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReconciliationController extends FinanceController
{
    public function __construct(private readonly ReconciliationService $service) {}

    public function overview(): JsonResponse
    {
        $b = $this->branchId();
        $settlements = PosSettlement::query()->with(['posAccount:id,name', 'bankAccount:id,name'])->withCount('cardDetails')
            ->orderByDesc('deposit_date')->orderByDesc('id')->limit(30)->get()
            ->map(fn (PosSettlement $s) => [
                'id' => $s->id, 'deposit_date' => $s->deposit_date->toDateString(), 'pos' => $s->posAccount?->name, 'bank' => $s->bankAccount?->name,
                'gross' => (string) $s->gross_amount, 'commission' => (string) $s->commission_amount, 'net' => (string) $s->net_amount, 'expected_net' => (string) $s->expected_net,
                'difference' => bcsub((string) $s->net_amount, (string) $s->expected_net, 2), 'count' => $s->card_details_count, 'reference' => $s->reference,
                'voided_at' => $s->voided_at?->toAtomString(), 'void_reason' => $s->void_reason,
            ]);

        return response()->json(['data' => [
            'pending' => $this->service->pendingSummary($b),
            'settlements' => $settlements,
            'bank' => $this->bankSummary($b),
        ]]);
    }

    /** Eşleşmeyi bekleyen kart tahsilatları (satır satır). */
    public function pendingCards(Request $request): JsonResponse
    {
        $q = DB::table('payment_card_details as d')->join('payments as p', 'p.id', '=', 'd.payment_id')->join('students as s', 's.id', '=', 'p.student_id')
            ->where('d.branch_id', $this->branchId())->whereNull('p.voided_at')
            ->when(! $request->boolean('settled'), fn ($x) => $x->whereNull('d.pos_settlement_id'))
            ->when($request->integer('settlement_id'), fn ($x, $v) => $x->where('d.pos_settlement_id', $v))
            ->when($request->integer('account_id'), fn ($x, $v) => $x->where('p.finance_account_id', $v))
            ->when($request->query('until'), fn ($x, $v) => $x->where('d.expected_deposit_date', '<=', $v))
            ->orderBy('d.expected_deposit_date')->orderBy('p.paid_at')->limit(1000)
            ->get(['d.id', 'd.payment_id', 'd.commission_rate', 'd.commission_amount', 'd.net_amount', 'd.expected_deposit_date', 'd.card_installments', 'd.pos_settlement_id',
                'p.receipt_no', 'p.paid_at', 'p.amount', 'p.method', 'p.finance_account_id', 's.full_name as student']);

        return response()->json(['data' => $q->map(fn ($r) => [
            'id' => $r->id, 'payment_id' => $r->payment_id, 'receipt_no' => $r->receipt_no, 'paid_at' => CarbonImmutable::parse($r->paid_at)->toAtomString(),
            'student' => $r->student, 'amount' => bcadd((string) $r->amount, '0', 2), 'commission_rate' => (string) $r->commission_rate,
            'commission' => bcadd((string) $r->commission_amount, '0', 2), 'net' => bcadd((string) $r->net_amount, '0', 2),
            'expected_date' => substr((string) $r->expected_deposit_date, 0, 10), 'installments' => (int) $r->card_installments,
            'method_label' => Payment::METHODS[$r->method] ?? $r->method, 'account_id' => (int) $r->finance_account_id, 'settlement_id' => $r->pos_settlement_id,
            'overdue' => substr((string) $r->expected_deposit_date, 0, 10) < CarbonImmutable::today()->toDateString(),
        ])]);
    }

    public function settle(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'pos_account_id' => ['required', 'integer'],
            'bank_account_id' => ['required', 'integer'],
            'deposit_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'actual_net' => ['required', 'string', self::MONEY],
            'detail_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'detail_ids.*' => ['integer'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ], ['deposit_date.before_or_equal' => 'Yatış tarihi ileri bir gün olamaz.']);
        $data['idempotency_key'] = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
        $s = $this->service->settle($data);

        return response()->json(['message' => 'POS yatışı eşleştirildi; komisyon gider olarak yazıldı.', 'id' => $s->id], 201);
    }

    public function voidSettlement(Request $request, PosSettlement $settlement): JsonResponse
    {
        $data = $this->validateTr($request, ['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $this->service->voidSettlement($settlement, $data['reason']);

        return $this->ok('Yatış eşleştirmesi iptal edildi; transfer ve komisyon kaydı ters kayıtla geri alındı.');
    }

    /** Banka / POS hesabı hareketleri ve eşleşme durumu. */
    public function bankTransactions(Request $request): JsonResponse
    {
        $accountId = $request->integer('account_id') ?: FinanceAccount::query()->where('kind', 'bank')->where('is_active', true)->value('id');
        $q = DB::table('account_transactions as t')->leftJoin('reconciliation_marks as m', 'm.account_transaction_id', '=', 't.id')
            ->where('t.branch_id', $this->branchId())->where('t.finance_account_id', $accountId)
            ->when($request->query('state') === 'open', fn ($x) => $x->whereNull('m.id'))
            ->when($request->query('state') === 'matched', fn ($x) => $x->whereNotNull('m.id'))
            ->when($request->date('from'), fn ($x, $v) => $x->where('t.occurred_at', '>=', $v->startOfDay()))
            ->when($request->date('to'), fn ($x, $v) => $x->where('t.occurred_at', '<=', $v->endOfDay()))
            ->orderByDesc('t.occurred_at')->orderByDesc('t.id')
            ->select(['t.id', 't.amount', 't.balance_after', 't.description', 't.occurred_at', 't.source_type', 'm.statement_date', 'm.statement_ref']);

        return $this->paginated($q->paginate($this->perPage($request, 50)), fn ($r) => [
            'id' => $r->id, 'amount' => bcadd((string) $r->amount, '0', 2), 'balance_after' => bcadd((string) $r->balance_after, '0', 2), 'description' => $r->description,
            'occurred_at' => CarbonImmutable::parse($r->occurred_at)->toAtomString(), 'source_type' => $r->source_type,
            'matched' => $r->statement_date !== null, 'statement_date' => $r->statement_date ? substr((string) $r->statement_date, 0, 10) : null, 'statement_ref' => $r->statement_ref,
        ], ['account_id' => $accountId]);
    }

    public function mark(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'transaction_ids' => ['required', 'array', 'min:1', 'max:500'], 'transaction_ids.*' => ['integer'],
            'statement_date' => ['required', 'date_format:Y-m-d'], 'statement_ref' => ['nullable', 'string', 'max:120'],
        ]);
        $n = $this->service->mark($data['transaction_ids'], $data['statement_date'], $data['statement_ref'] ?? null);

        return $this->ok("{$n} hareket eşleşti olarak işaretlendi.");
    }

    public function unmark(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, ['transaction_ids' => ['required', 'array', 'min:1', 'max:500'], 'transaction_ids.*' => ['integer']]);
        $n = $this->service->unmark($data['transaction_ids']);

        return $this->ok("{$n} hareketin eşleşme işareti kaldırıldı.");
    }

    private function bankSummary(int $b): array
    {
        return DB::table('account_transactions as t')->join('finance_accounts as a', 'a.id', '=', 't.finance_account_id')
            ->leftJoin('reconciliation_marks as m', 'm.account_transaction_id', '=', 't.id')
            ->where('t.branch_id', $b)->where('a.kind', 'bank')->whereNull('a.deleted_at')
            ->groupBy('a.id', 'a.name', 'a.balance')
            ->selectRaw('a.id, a.name, a.balance, SUM(m.id IS NULL) AS open_count, COALESCE(SUM(CASE WHEN m.id IS NULL THEN t.amount ELSE 0 END), 0) AS open_amount, COUNT(*) AS total')
            ->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'balance' => bcadd((string) $r->balance, '0', 2), 'open_count' => (int) $r->open_count,
                'open_amount' => bcadd((string) $r->open_amount, '0', 2), 'total' => (int) $r->total])->all();
    }
}
