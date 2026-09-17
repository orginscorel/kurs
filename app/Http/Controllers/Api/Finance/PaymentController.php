<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\FinanceAccount;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\AmountInWords;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\PaymentService;
use App\Support\Audit;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends FinanceController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $totals = (clone $query)->reorder()->select([])->selectRaw('
            COUNT(*) AS count,
            COALESCE(SUM(CASE WHEN payments.voided_at IS NULL THEN payments.amount ELSE 0 END), 0) AS active_amount,
            SUM(payments.voided_at IS NULL) AS active_count,
            COALESCE(SUM(CASE WHEN payments.voided_at IS NOT NULL THEN payments.amount ELSE 0 END), 0) AS voided_amount,
            SUM(payments.voided_at IS NOT NULL) AS voided_count')->first();

        $this->applySort($query, $request, ['paid_at' => 'payments.paid_at', 'amount' => 'payments.amount', 'receipt_no' => 'payments.receipt_no'], '-paid_at');
        $query->orderByDesc('payments.id');

        return $this->paginated($query->paginate($this->perPage($request)), fn (Payment $p) => $this->row($p), [
            'totals' => [
                'count' => (int) $totals->count,
                'active_count' => (int) $totals->active_count,
                'active_amount' => bcadd((string) $totals->active_amount, '0', 2),
                'voided_count' => (int) $totals->voided_count,
                'voided_amount' => bcadd((string) $totals->voided_amount, '0', 2),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderBy('payments.paid_at');
        Audit::log('payment.exported', 'tahsilat listesini Excel olarak dışa aktardı.');

        return $this->xlsx('tahsilatlar-'.now()->format('Y-m-d').'.xlsx',
            ['Makbuz No', 'Tarih', 'Öğrenci No', 'Öğrenci', 'Ödeyen', 'Yöntem', 'Hesap', 'Tutar', 'Referans', 'Tahsil Eden', 'Durum', 'İptal Gerekçesi', 'Not'],
            function (callable $add) use ($query) {
                $query->chunk(500, function ($chunk) use ($add) {
                    foreach ($chunk as $p) {
                        $add([
                            $p->receipt_no, $p->paid_at->format('d.m.Y H:i'), $p->student?->student_no ?? '', $p->student?->full_name ?? '', $p->payer_name ?? '',
                            Payment::METHODS[$p->method] ?? $p->method, $p->account?->name ?? '', $this->cell($p->amount), $p->reference ?? '',
                            $p->receiver?->name ?? '', $p->voided_at ? 'İptal' : 'Geçerli', $p->void_reason ?? '', $p->note ?? '',
                        ]);
                    }
                });
            });
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['student', 'account', 'receiver', 'enrollment.program', 'allocations.installment']);
        $guardian = $payment->guardian_id ? DB::table('guardians')->where('id', $payment->guardian_id)->first(['id', 'first_name', 'last_name']) : null;

        return response()->json(['data' => [
            ...$this->row($payment),
            'note' => $payment->note,
            'amount_words' => AmountInWords::lira((string) $payment->amount),
            'enrollment' => $payment->enrollment ? ['id' => $payment->enrollment->id, 'enrollment_no' => $payment->enrollment->enrollment_no, 'program' => $payment->enrollment->program?->name] : null,
            'guardian' => $guardian ? ['id' => $guardian->id, 'name' => trim($guardian->first_name.' '.$guardian->last_name)] : null,
            'voided_by' => $payment->voided_by ? User::query()->whereKey($payment->voided_by)->value('name') : null,
            'created_at' => $payment->created_at?->toAtomString(),
            'credit' => PaymentService::unallocated($payment),
            'refundable' => \App\Services\Finance\RefundService::refundable($payment),
            'refunds' => DB::table('refunds')->where('payment_id', $payment->id)->orderBy('id')->get(['id', 'refund_no', 'amount', 'refunded_at', 'voided_at']),
            'invoices' => DB::table('invoice_payments as ip')->join('invoices as i', 'i.id', '=', 'ip.invoice_id')->where('ip.payment_id', $payment->id)
                ->orderBy('i.id')->get(['i.id', 'i.invoice_no', 'i.status', 'ip.amount']),
            'card' => DB::table('payment_card_details')->where('payment_id', $payment->id)->first(['commission_rate', 'commission_amount', 'net_amount', 'expected_deposit_date', 'card_installments', 'pos_settlement_id']),
            'allocations' => $payment->allocations->map(fn ($a) => [
                'installment_id' => $a->installment_id,
                'sequence' => $a->installment?->sequence,
                'due_date' => $a->installment?->due_date?->toDateString(),
                'installment_amount' => (string) $a->installment?->amount,
                'installment_status' => $a->installment?->status,
                'amount' => (string) $a->amount,
            ])->sortBy('due_date')->values(),
        ]]);
    }

    /** Tahsilat ekranı için öğrencinin açık taksitleri, velileri ve son ödemeleri. */
    public function studentContext(Request $request, Student $student): JsonResponse
    {
        $sensitive = $request->user()->can('students.view_sensitive');
        $student->load('guardians');
        $today = CarbonImmutable::today();

        $open = Installment::query()->where('student_id', $student->id)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->with('enrollment:id,enrollment_no,program_id,academic_term_id', 'enrollment.program:id,name', 'enrollment.term:id,name')
            ->orderBy('due_date')->orderBy('sequence')->get();

        $totals = Installment::query()->where('student_id', $student->id)->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(paid_amount), 0) AS paid')->first();

        $remaining = $open->reduce(fn ($s, Installment $i) => bcadd($s, $i->remaining(), 2), '0.00');
        $overdue = $open->filter(fn (Installment $i) => $i->due_date->lt($today))->reduce(fn ($s, Installment $i) => bcadd($s, $i->remaining(), 2), '0.00');

        return response()->json([
            'student' => [
                'id' => $student->id, 'full_name' => $student->full_name, 'student_no' => $student->student_no, 'status' => $student->status,
                'status_label' => Student::STATUSES[$student->status] ?? $student->status,
                'photo_url' => $student->photo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($student->photo_path) : null,
            ],
            'guardians' => $student->guardians->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->full_name, 'phone' => $sensitive ? $g->phone : Sensitive::maskPhone($g->phone),
                'relationship' => $g->pivot->relationship, 'is_primary' => (bool) $g->pivot->is_primary, 'is_financially_responsible' => (bool) $g->pivot->is_financially_responsible,
            ])->sortByDesc(fn ($g) => (int) $g['is_financially_responsible'] * 2 + (int) $g['is_primary'])->values(),
            'summary' => [
                'total' => bcadd((string) $totals->total, '0', 2), 'paid' => bcadd((string) $totals->paid, '0', 2),
                'remaining' => $remaining, 'overdue' => $overdue, 'open_count' => $open->count(),
                'credit' => \App\Services\Finance\StudentCredit::forStudent($student->id),
            ],
            'installments' => $open->map(fn (Installment $i) => [
                'id' => $i->id, 'enrollment_id' => $i->enrollment_id, 'enrollment_no' => $i->enrollment?->enrollment_no,
                'program' => $i->enrollment?->program?->name, 'term' => $i->enrollment?->term?->name,
                'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(), 'amount' => (string) $i->amount,
                'paid_amount' => (string) $i->paid_amount, 'remaining' => $i->remaining(), 'status' => $i->status,
                'days_overdue' => max(0, (int) $i->due_date->diffInDays($today, false)),
            ]),
            'recent_payments' => Payment::query()->where('student_id', $student->id)->with('account:id,name')->orderByDesc('paid_at')->limit(5)->get()
                ->map(fn (Payment $p) => ['id' => $p->id, 'receipt_no' => $p->receipt_no, 'amount' => (string) $p->amount, 'method' => $p->method, 'paid_at' => $p->paid_at->toAtomString(), 'account' => $p->account?->name, 'voided_at' => $p->voided_at?->toAtomString()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'student_id' => ['required', 'integer'],
            'finance_account_id' => ['required', 'integer'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'amount' => ['required', 'string', self::MONEY],
            'paid_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(10)->toDateTimeString()],
            'installment_ids' => ['nullable', 'array', 'max:100'],
            'installment_ids.*' => ['integer'],
            'guardian_id' => ['nullable', 'integer'],
            'payer_name' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'overpayment' => ['nullable', Rule::in(['reject', 'next', 'credit'])],
            'commission_rate' => ['nullable', 'regex:/^\d{1,2}([.,]\d{1,3})?$/'],
            'card_installments' => ['nullable', 'integer', 'min:1', 'max:12'],
        ], [
            'amount.regex' => 'Tutarı 1500 ya da 1500,50 biçiminde girin.',
            'paid_at.before_or_equal' => 'Tahsilat tarihi ileri bir zaman olamaz.',
        ]);

        $key = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
        $student = Student::query()->findOrFail($data['student_id']);
        $account = FinanceAccount::query()->findOrFail($data['finance_account_id']);
        if (! $account->is_active) {
            throw new BusinessRuleException("\"{$account->name}\" hesabı pasif durumda.", 'account_inactive');
        }
        if (! empty($data['guardian_id']) && ! $student->guardians()->whereKey($data['guardian_id'])->exists()) {
            throw new BusinessRuleException('Seçilen veli bu öğrenciye bağlı değil.', 'guardian_mismatch');
        }
        if (! empty($data['installment_ids'])) {
            $found = Installment::query()->where('student_id', $student->id)->whereIn('id', $data['installment_ids'])->count();
            if ($found !== count(array_unique($data['installment_ids']))) {
                throw new BusinessRuleException('Seçilen taksitlerden bazıları bu öğrenciye ait değil.', 'installment_mismatch');
            }
        }

        $existing = $key ? Payment::query()->where('idempotency_key', $key)->first() : null;
        if ($existing && $existing->student_id !== $student->id) {
            throw new BusinessRuleException('Bu işlem anahtarı başka bir tahsilatta kullanılmış. Sayfayı yenileyip tekrar deneyin.', 'idempotency_conflict', [], 409);
        }

        try {
            $payment = $this->payments->collect($student, [
                'finance_account_id' => $account->id,
                'method' => $data['method'],
                'amount' => $data['amount'],
                'paid_at' => $data['paid_at'] ?? null,
                'installment_ids' => $data['installment_ids'] ?? null,
                'guardian_id' => $data['guardian_id'] ?? null,
                'payer_name' => $data['payer_name'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'idempotency_key' => $key,
                'overpayment' => $data['overpayment'] ?? 'reject',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Aynı anahtarla eşzamanlı ikinci istek: ilk kayıt döner, çift tahsilat oluşmaz.
            $payment = $key ? Payment::query()->where('idempotency_key', $key)->first() : null;
            if (! $payment) {
                throw $e;
            }
        }

        $duplicate = $existing !== null || $payment->wasRecentlyCreated === false;
        if (! $duplicate && (isset($data['commission_rate']) || isset($data['card_installments']))) {
            app(\App\Services\Finance\CardPaymentDetails::class)->override($payment, isset($data['commission_rate']) ? str_replace(',', '.', $data['commission_rate']) : null, $data['card_installments'] ?? null);
        }

        return response()->json([
            'message' => $duplicate ? 'Bu tahsilat zaten kaydedilmişti.' : 'Tahsilat kaydedildi.',
            'duplicate' => $duplicate,
            'data' => $this->row($payment->load(['student', 'account', 'receiver'])),
        ], $duplicate ? 200 : 201);
    }

    public function void(Request $request, Payment $payment): JsonResponse
    {
        $data = $this->validateTr($request, ['reason' => ['required', 'string', 'min:5', 'max:300']], ['reason.min' => 'İptal gerekçesi en az 5 karakter olmalı.']);
        $this->payments->void($payment, $data['reason']);

        return $this->ok("{$payment->receipt_no} numaralı tahsilat iptal edildi; taksitler ve hesap bakiyesi geri alındı.");
    }

    public function receipt(Request $request, Payment $payment, FinanceDocuments $documents): Response
    {
        return $documents->receiptPdf($payment, $request->boolean('inline'));
    }

    private function filtered(Request $request): Builder
    {
        $query = Payment::query()->select('payments.*')->with([
            'student:id,full_name,student_no', 'account:id,name,kind', 'receiver:id,name',
            'enrollment:id,program_id,enrollment_no', 'enrollment.program:id,name',
            // Hangi taksitlere düştüğü (sıra no) — toplu eager load, N+1 yok
            'allocations:id,payment_id,installment_id,amount', 'allocations.installment:id,sequence',
        ]);

        if ($receipt = trim((string) $request->query('makbuz'))) {
            $query->where('payments.receipt_no', $receipt);
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(function (Builder $w) use ($q) {
                $w->where('payments.receipt_no', 'like', strtoupper($q).'%')
                    ->orWhere('payments.reference', 'like', $q.'%')
                    ->orWhere('payments.payer_name', 'like', '%'.$q.'%')
                    ->orWhereIn('payments.student_id', Student::query()->withTrashed()->where('full_name', 'like', '%'.$q.'%')->select('id'));
            });
        }
        if ($method = $request->query('method')) {
            $query->where('payments.method', $method);
        }
        if ($account = $request->integer('account_id')) {
            $query->where('payments.finance_account_id', $account);
        }
        if ($student = $request->integer('student_id')) {
            $query->where('payments.student_id', $student);
        }
        if ($user = $request->integer('received_by')) {
            $query->where('payments.received_by', $user);
        }
        match ($request->query('status')) {
            'active' => $query->whereNull('payments.voided_at'),
            'voided' => $query->whereNotNull('payments.voided_at'),
            default => null,
        };
        $this->applyDateRange($query, $request, 'payments.paid_at');

        return $query;
    }

    private function row(Payment $p): array
    {
        return [
            'id' => $p->id,
            'receipt_no' => $p->receipt_no,
            'amount' => (string) $p->amount,
            'method' => $p->method,
            'method_label' => Payment::METHODS[$p->method] ?? $p->method,
            'paid_at' => $p->paid_at->toAtomString(),
            'student' => $p->student ? ['id' => $p->student->id, 'full_name' => $p->student->full_name, 'student_no' => $p->student->student_no] : null,
            'account' => $p->account ? ['id' => $p->account->id, 'name' => $p->account->name] : null,
            'received_by' => $p->receiver?->name,
            'payer_name' => $p->payer_name,
            'reference' => $p->reference,
            'voided_at' => $p->voided_at?->toAtomString(),
            'void_reason' => $p->void_reason,
            'program' => $p->relationLoaded('enrollment') ? $p->enrollment?->program?->name : null,
            'enrollment_no' => $p->relationLoaded('enrollment') ? $p->enrollment?->enrollment_no : null,
            'installment_sequences' => $p->relationLoaded('allocations')
                ? $p->allocations->map(fn ($a) => $a->installment?->sequence)->filter()->unique()->sort()->values()->all()
                : null,
        ];
    }
}
