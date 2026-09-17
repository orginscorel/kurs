<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\Payment;
use App\Models\Refund;
use App\Services\Finance\FinanceExtraDocuments;
use App\Services\Finance\PaymentService;
use App\Services\Finance\RefundService;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class RefundController extends FinanceController
{
    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $query = Refund::query()->with(['student:id,full_name,student_no', 'payment:id,receipt_no,amount,paid_at', 'account:id,name', 'creator:id,name']);
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('refund_no', 'like', strtoupper($q).'%')
                ->orWhereIn('student_id', \App\Models\Student::query()->withTrashed()->where('full_name', 'like', "%{$q}%")->select('id')));
        }
        match ($request->query('status')) {
            'active' => $query->whereNull('voided_at'),
            'voided' => $query->whereNotNull('voided_at'),
            default => null,
        };
        if ($s = $request->integer('student_id')) {
            $query->where('student_id', $s);
        }
        $this->applyDateRange($query, $request, 'refunded_at');
        $totals = (clone $query)->reorder()->select([])->selectRaw('COUNT(*) AS c, COALESCE(SUM(CASE WHEN voided_at IS NULL THEN amount ELSE 0 END), 0) AS s')->first();
        $query->orderByDesc('refunded_at')->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request)), fn (Refund $r) => $this->row($r), [
            'totals' => ['count' => (int) $totals->c, 'amount' => bcadd((string) $totals->s, '0', 2)],
        ]);
    }

    /** İade penceresi için tahsilat bilgisi. */
    public function context(Payment $payment, InvoiceService $invoices): JsonResponse
    {
        $issuedLinked = (string) DB::table('invoice_payments as ip')->join('invoices as i', 'i.id', '=', 'ip.invoice_id')
            ->where('ip.payment_id', $payment->id)->where('i.status', 'issued')->where('i.kind', 'sales')->sum('ip.amount');

        return response()->json(['data' => [
            'payment_id' => $payment->id, 'receipt_no' => $payment->receipt_no, 'amount' => (string) $payment->amount,
            'refundable' => RefundService::refundable($payment), 'credit' => PaymentService::unallocated($payment),
            'invoiced' => bcadd($issuedLinked, '0', 2), 'voided' => $payment->voided_at !== null,
            'method' => $payment->method, 'finance_account_id' => $payment->finance_account_id, 'payer_name' => $payment->payer_name,
            'previous' => Refund::query()->where('payment_id', $payment->id)->orderBy('id')->get()->map(fn (Refund $r) => $this->row($r)),
        ]]);
    }

    public function store(Request $request, Payment $payment): JsonResponse
    {
        $data = $this->validateTr($request, [
            'amount' => ['required', 'string', self::MONEY],
            'finance_account_id' => ['required', 'integer'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'refunded_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
            'payee_name' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ], ['reason.min' => 'İade gerekçesi en az 5 karakter olmalı.', 'amount.regex' => 'Tutarı 1500 ya da 1500,50 biçiminde girin.']);
        $data['idempotency_key'] = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
        $refund = $this->refunds->refund($payment, $data);

        return response()->json(['message' => "{$refund->refund_no} numaralı iade kaydedildi.", 'data' => $this->row($refund->load(['student', 'payment', 'account', 'creator']))], $refund->wasRecentlyCreated ? 201 : 200);
    }

    public function void(Request $request, Refund $refund): JsonResponse
    {
        $data = $this->validateTr($request, ['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $this->refunds->void($refund, $data['reason']);

        return $this->ok("{$refund->refund_no} numaralı iade iptal edildi; tutar hesaba, taksitler eski durumuna döndü.");
    }

    public function pdf(Request $request, Refund $refund, FinanceExtraDocuments $docs): Response
    {
        return $docs->refund($refund, $request->boolean('inline'));
    }

    private function row(Refund $r): array
    {
        return [
            'id' => $r->id, 'refund_no' => $r->refund_no, 'amount' => (string) $r->amount, 'from_credit' => (string) $r->from_credit,
            'from_installments' => (string) $r->from_installments, 'invoiced_portion' => (string) $r->invoiced_portion,
            'method' => $r->method, 'method_label' => Payment::METHODS[$r->method] ?? $r->method, 'refunded_at' => $r->refunded_at->toAtomString(),
            'reason' => $r->reason, 'payee_name' => $r->payee_name, 'reference' => $r->reference,
            'student' => $r->student ? ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no] : null,
            'payment' => $r->payment ? ['id' => $r->payment->id, 'receipt_no' => $r->payment->receipt_no, 'amount' => (string) $r->payment->amount] : null,
            'account' => $r->account?->name, 'created_by' => $r->creator?->name,
            'voided_at' => $r->voided_at?->toAtomString(), 'void_reason' => $r->void_reason,
        ];
    }
}
