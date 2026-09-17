<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Accounting\FinanceSettings;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceExtraDocuments;
use App\Services\Invoicing\InvoiceMath;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\Integrators\IntegratorManager;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends FinanceController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $totals = (clone $query)->reorder()->select([])->selectRaw("
            COUNT(*) AS count,
            SUM(invoices.status = 'draft') AS drafts,
            COALESCE(SUM(CASE WHEN invoices.status = 'issued' AND invoices.kind = 'sales' THEN invoices.payable_total ELSE 0 END), 0) AS issued_total,
            COALESCE(SUM(CASE WHEN invoices.status = 'issued' AND invoices.kind = 'sales' THEN invoices.vat_total ELSE 0 END), 0) AS vat_total,
            COALESCE(SUM(CASE WHEN invoices.status = 'issued' AND invoices.kind = 'return' THEN invoices.payable_total ELSE 0 END), 0) AS return_total")->first();
        $this->applySort($query, $request, ['issue_date' => 'invoices.issue_date', 'total' => 'invoices.payable_total', 'invoice_no' => 'invoices.invoice_no'], '-issue_date');
        $query->orderByDesc('invoices.id');

        $counts = Invoice::query()->selectRaw("SUM(status = 'draft') AS draft, SUM(status = 'issued') AS issued, SUM(status = 'cancelled') AS cancelled, COUNT(*) AS `all`")->first();

        return $this->paginated($query->paginate($this->perPage($request)), fn (Invoice $i) => $this->row($i, $request), [
            'totals' => [
                'count' => (int) $totals->count, 'drafts' => (int) $totals->drafts,
                'issued_total' => $this->dec($totals->issued_total), 'vat_total' => $this->dec($totals->vat_total), 'return_total' => $this->dec($totals->return_total),
            ],
            'status_counts' => collect((array) $counts->getAttributes())->map(fn ($v) => (int) $v),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderBy('invoices.issue_date')->orderBy('invoices.id');
        FinanceAudit::log('invoice.exported', 'fatura listesini Excel olarak dışa aktardı.');

        return $this->xlsx('faturalar-'.now()->format('Y-m-d').'.xlsx',
            ['Fatura no', 'Tarih', 'Tür', 'Belge', 'Durum', 'Alıcı', 'TCKN/VKN', 'Öğrenci', 'Matrah', 'KDV', 'Tevkifat', 'Ödenecek', 'İptal gerekçesi'],
            function (callable $add) use ($query, $request) {
                $sensitive = $request->user()->can('students.view_sensitive');
                $query->chunk(500, function ($chunk) use ($add, $sensitive) {
                    foreach ($chunk as $i) {
                        $sign = $i->kind === 'return' ? -1 : 1;
                        $add([$i->invoice_no ?? 'Taslak #'.$i->id, $i->issue_date->format('d.m.Y'), Invoice::KINDS[$i->kind], Invoice::DOCUMENT_TYPES[$i->document_type] ?? '', Invoice::STATUSES[$i->status],
                            $i->buyer_name, $sensitive ? ($i->buyer_tax_id ?? '') : ($i->buyer_tax_id_last4 ? '*******'.$i->buyer_tax_id_last4 : ''), $i->student?->full_name ?? '',
                            $sign * $this->cell($i->net_total), $sign * $this->cell($i->vat_total), $sign * $this->cell($i->withholding_total), $sign * $this->cell($i->payable_total), $i->cancel_reason ?? '']);
                    }
                });
            });
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $invoice->load(['lines', 'payments', 'related', 'student']);
        $returns = Invoice::query()->where('related_invoice_id', $invoice->id)->orderBy('id')->get(['id', 'invoice_no', 'status', 'payable_total', 'issue_date']);
        $users = DB::table('users')->whereIn('id', array_filter([$invoice->created_by, $invoice->issued_by, $invoice->cancelled_by]))->pluck('name', 'id');
        $journal = DB::table('journal_entries')->where(fn ($q) => $q->where(fn ($w) => $w->where('source_type', 'invoice')->where('source_id', $invoice->id))
            ->orWhere(fn ($w) => $w->where('source_type', 'invoice_payment')->whereIn('source_id', DB::table('invoice_payments')->where('invoice_id', $invoice->id)->select('id'))))
            ->orderBy('id')->get(['id', 'entry_no', 'entry_date', 'source_event', 'total_debit']);
        $linked = $invoice->payments->reduce(fn ($s, $p) => bcadd($s, (string) $p->pivot->amount, 2), '0.00');

        return response()->json(['data' => [
            ...$this->row($invoice, $request),
            'buyer_tax_id' => $request->user()->can('students.view_sensitive') || $request->user()->can('finance.invoice') ? $invoice->buyer_tax_id : null,
            'buyer_tax_office' => $invoice->buyer_tax_office, 'buyer_address' => $invoice->buyer_address, 'buyer_email' => $invoice->buyer_email, 'buyer_phone' => $invoice->buyer_phone,
            'enrollment_id' => $invoice->enrollment_id, 'notes' => $invoice->notes, 'prices_include_vat' => $invoice->prices_include_vat, 'ettn' => $invoice->ettn,
            'gross_total' => (string) $invoice->gross_total, 'discount_total' => (string) $invoice->discount_total, 'grand_total' => (string) $invoice->grand_total,
            'amount_words' => InvoiceService::amountWords($invoice),
            'lines' => $invoice->lines->map(fn ($l) => [
                'id' => $l->id, 'sequence' => $l->sequence, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit' => $l->unit,
                'unit_price' => (string) $l->unit_price, 'discount_rate' => (string) $l->discount_rate, 'discount_amount' => (string) $l->discount_amount,
                'vat_rate' => (string) $l->vat_rate, 'withholding_tenths' => $l->withholding_tenths, 'gross_amount' => (string) $l->gross_amount,
                'net_amount' => (string) $l->net_amount, 'vat_amount' => (string) $l->vat_amount, 'withholding_amount' => (string) $l->withholding_amount, 'total_amount' => (string) $l->total_amount,
            ]),
            'vat_breakdown' => InvoiceMath::totals($invoice->lines->map(fn ($l) => ['vat_rate' => (string) $l->vat_rate, 'net_amount' => (string) $l->net_amount, 'vat_amount' => (string) $l->vat_amount,
                'gross_amount' => '0', 'discount_amount' => '0', 'withholding_amount' => '0', 'total_amount' => '0'])->all())['vat_breakdown'],
            'payments' => $invoice->payments->map(fn (Payment $p) => ['id' => $p->id, 'receipt_no' => $p->receipt_no, 'paid_at' => $p->paid_at->toAtomString(), 'amount' => (string) $p->amount, 'linked' => (string) $p->pivot->amount, 'voided' => $p->voided_at !== null]),
            'linked_total' => $linked,
            'open_amount' => $invoice->kind === 'sales' ? bcsub((string) $invoice->payable_total, $linked, 2) : '0.00',
            'related' => $invoice->related ? ['id' => $invoice->related->id, 'invoice_no' => $invoice->related->invoice_no, 'payable_total' => (string) $invoice->related->payable_total] : null,
            'returns' => $returns,
            'journal' => $journal,
            'created_by' => $users[$invoice->created_by] ?? null, 'issued_by' => $users[$invoice->issued_by] ?? null, 'cancelled_by' => $users[$invoice->cancelled_by] ?? null,
            'cancel_reason' => $invoice->cancel_reason, 'cancelled_at' => $invoice->cancelled_at?->toAtomString(), 'issued_at' => $invoice->issued_at?->toAtomString(),
            'created_at' => $invoice->created_at?->toAtomString(),
        ]]);
    }

    public function options(IntegratorManager $integrators): JsonResponse
    {
        $s = FinanceSettings::all();

        return response()->json(['data' => [
            'vat_rates' => $s['vat_rates'], 'default_vat_rate' => (string) $s['default_vat_rate'], 'prices_include_vat' => (bool) $s['prices_include_vat'],
            'withholding_enabled' => (bool) $s['withholding_enabled'], 'default_unit' => $s['default_unit'], 'default_document_type' => $s['default_document_type'],
            'invoice_prefix' => $this->invoices->prefixFor('sales'), 'return_prefix' => $this->invoices->prefixFor('return'),
            'integrator' => ['key' => $integrators->current()->key(), 'label' => $integrators->current()->label(), 'connected' => $integrators->current()->connected()],
            'document_types' => Invoice::DOCUMENT_TYPES, 'buyer_types' => Invoice::BUYER_TYPES,
            'units' => ['ADET', 'AY', 'SAAT', 'DERS', 'DÖNEM', 'KİŞİ'],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $invoice = $this->invoices->saveDraft($data);

        return response()->json(['message' => 'Fatura taslağı kaydedildi.', 'id' => $invoice->id], 201);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->invoices->saveDraft($this->validated($request), $invoice);

        return $this->ok('Taslak güncellendi.', ['id' => $invoice->id]);
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->invoices->deleteDraft($invoice);

        return $this->ok('Taslak silindi.');
    }

    /** Kaydetmeden hesap önizlemesi (form canlı toplamı; sunucu kuralıyla aynı). */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'prices_include_vat' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.quantity' => ['nullable', 'regex:/^\d{1,9}([.,]\d{1,3})?$/'],
            'lines.*.unit_price' => ['nullable', 'string', self::MONEY],
            'lines.*.discount_rate' => ['nullable', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
            'lines.*.discount_amount' => ['nullable', 'string', self::MONEY],
            'lines.*.vat_rate' => ['required', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
            'lines.*.withholding_tenths' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);
        $lines = array_map(fn ($l) => InvoiceMath::line($this->normalizeLine($l), (bool) ($data['prices_include_vat'] ?? true)), $data['lines']);

        return response()->json(['data' => ['lines' => $lines, 'totals' => InvoiceMath::totals($lines)]]);
    }

    public function issue(Invoice $invoice): JsonResponse
    {
        $inv = $this->invoices->issue($invoice);

        return $this->ok("{$inv->invoice_no} numaralı fatura kesildi.", ['id' => $inv->id, 'invoice_no' => $inv->invoice_no, 'integrator_status' => $inv->integrator_status]);
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $this->validateTr($request, ['reason' => ['required', 'string', 'min:5', 'max:300']], ['reason.min' => 'İptal gerekçesi en az 5 karakter olmalı.']);
        $this->invoices->cancel($invoice, $data['reason']);

        return $this->ok("{$invoice->invoice_no} numaralı fatura iptal edildi; muhasebe fişi ters kayıtla kapatıldı.");
    }

    public function createReturn(Request $request, Invoice $invoice): JsonResponse
    {
        $draft = $this->invoices->createReturnDraft($invoice, $request->header('Idempotency-Key') ?: $request->input('idempotency_key'));

        return response()->json(['message' => 'İade faturası taslağı oluşturuldu; kalemleri kontrol edip kesin.', 'id' => $draft->id], 201);
    }

    public function linkPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $this->validateTr($request, ['payment_id' => ['required', 'integer'], 'amount' => ['nullable', 'string', self::MONEY]]);
        $payment = Payment::query()->findOrFail($data['payment_id']);
        $this->invoices->linkPayment($invoice, $payment, $data['amount'] ?? null);

        return $this->ok("{$payment->receipt_no} makbuzu faturaya bağlandı.");
    }

    public function fromEnrollment(Enrollment $enrollment): JsonResponse
    {
        $invoice = $this->invoices->draftFromEnrollment($enrollment);

        return response()->json(['message' => 'Kayıttan fatura taslağı oluşturuldu.', 'id' => $invoice->id], 201);
    }

    public function unbilled(Request $request): JsonResponse
    {
        $query = $this->invoices->unbilledQuery($this->branchId());
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('s.full_name', 'like', "%{$q}%")->orWhere('p.receipt_no', 'like', strtoupper($q).'%'));
        }
        if ($from = $request->date('from')) {
            $query->where('p.paid_at', '>=', $from->startOfDay());
        }
        if ($to = $request->date('to')) {
            $query->where('p.paid_at', '<=', $to->endOfDay());
        }
        if ($student = $request->integer('student_id')) {
            $query->where('p.student_id', $student);
        }
        $totals = (clone $query)->selectRaw('COUNT(*) AS c, COALESCE(SUM(p.amount - COALESCE(l.linked, 0) - COALESCE(r.refunded, 0)), 0) AS s, COUNT(DISTINCT p.student_id) AS st')->first();
        $page = $query->orderByDesc('p.paid_at')->orderByDesc('p.id')
            ->select(['p.id', 'p.receipt_no', 'p.paid_at', 'p.amount', 'p.method', 'p.student_id', 's.full_name as student', 's.student_no', 'pr.name as program',
                DB::raw('(p.amount - COALESCE(l.linked, 0) - COALESCE(r.refunded, 0)) AS available')])
            ->paginate($this->perPage($request, 50));

        return $this->paginated($page, fn ($r) => [
            'id' => $r->id, 'receipt_no' => $r->receipt_no, 'paid_at' => CarbonImmutable::parse($r->paid_at)->toAtomString(), 'amount' => $this->dec($r->amount),
            'available' => $this->dec($r->available), 'method' => $r->method, 'method_label' => Payment::METHODS[$r->method] ?? $r->method,
            'student_id' => $r->student_id, 'student' => $r->student, 'student_no' => $r->student_no, 'program' => $r->program,
        ], ['totals' => ['count' => (int) $totals->c, 'amount' => $this->dec($totals->s), 'students' => (int) $totals->st]]);
    }

    public function bulkDrafts(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'payment_ids' => ['required', 'array', 'min:1', 'max:300'],
            'payment_ids.*' => ['integer'],
            'group' => ['nullable', Rule::in(['payment', 'student'])],
        ]);
        $ids = $this->invoices->draftsFromPayments($data['payment_ids'], $data['group'] ?? 'payment');

        return $this->ok(count($ids) ? count($ids).' fatura taslağı oluşturuldu. Kontrol edip "Kes" ile kesinleştirin.' : 'Seçilen tahsilatların faturalanacak tutarı kalmamış.', ['ids' => $ids]);
    }

    public function pdf(Request $request, Invoice $invoice, FinanceExtraDocuments $docs): Response
    {
        return $docs->invoice($invoice, $request->boolean('inline'));
    }

    public function sendToIntegrator(Invoice $invoice, IntegratorManager $integrators): JsonResponse
    {
        if ($invoice->status !== 'issued') {
            return response()->json(['message' => 'Yalnız kesilmiş fatura gönderilebilir.'], 422);
        }
        $result = $integrators->current()->send($invoice);
        $invoice->forceFill(['integrator_status' => $result['status'], 'ettn' => $result['ettn'] ?? $invoice->ettn])->save();
        FinanceAudit::log('invoice.integrator_send', "{$invoice->invoice_no}: {$result['message']}", $invoice);

        return $this->ok($result['message'], ['integrator_status' => $result['status']]);
    }

    // ------------------------------------------------------------------

    private function validated(Request $request): array
    {
        $data = $this->validateTr($request, [
            'document_type' => ['nullable', Rule::in(array_keys(Invoice::DOCUMENT_TYPES))],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'buyer_type' => ['required', Rule::in(array_keys(Invoice::BUYER_TYPES))],
            'buyer_id' => ['nullable', 'integer'],
            'buyer_name' => ['required', 'string', 'min:2', 'max:200'],
            'buyer_tax_id' => ['nullable', 'string', 'max:20'],
            'buyer_tax_office' => ['nullable', 'string', 'max:120'],
            'buyer_address' => ['nullable', 'string', 'max:400'],
            'buyer_email' => ['nullable', 'email', 'max:160'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'student_id' => ['nullable', 'integer'],
            'enrollment_id' => ['nullable', 'integer'],
            'prices_include_vat' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'min:2', 'max:300'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,9}([.,]\d{1,3})?$/'],
            'lines.*.unit' => ['nullable', 'string', 'max:12'],
            'lines.*.unit_price' => ['required', 'string', self::MONEY],
            'lines.*.discount_rate' => ['nullable', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
            'lines.*.discount_amount' => ['nullable', 'string', self::MONEY],
            'lines.*.vat_rate' => ['required', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
            'lines.*.withholding_tenths' => ['nullable', 'integer', 'min:0', 'max:10'],
            'payments' => ['nullable', 'array', 'max:100'],
            'payments.*.payment_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'string', self::MONEY],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ], ['lines.*.unit_price.regex' => 'Birim fiyatı 1500 ya da 1500,50 biçiminde girin.', 'lines.required' => 'Faturada en az bir kalem olmalı.']);
        $data['lines'] = array_map(fn ($l) => $this->normalizeLine($l) + ['description' => $l['description'], 'unit' => $l['unit'] ?? null], $data['lines']);
        if (isset($data['payments'])) {
            $data['payments'] = array_map(fn ($p) => ['payment_id' => (int) $p['payment_id'], 'amount' => \App\Support\Money::of($p['amount'])], $data['payments']);
        }
        if (! $request->has('payments')) {
            unset($data['payments']);
        }
        $data['idempotency_key'] = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
        if (! empty($data['student_id']) && ! DB::table('students')->where('id', $data['student_id'])->where('branch_id', $this->branchId())->exists()) {
            abort(404);
        }

        return $data;
    }

    private function normalizeLine(array $l): array
    {
        $dec = fn ($v) => $v === null || $v === '' ? '0' : str_replace(',', '.', (string) $v);

        return [
            'quantity' => $dec($l['quantity'] ?? '1'),
            'unit_price' => isset($l['unit_price']) && $l['unit_price'] !== '' ? \App\Support\Money::of($l['unit_price']) : '0',
            'discount_rate' => $dec($l['discount_rate'] ?? '0'),
            'discount_amount' => isset($l['discount_amount']) && $l['discount_amount'] !== '' ? \App\Support\Money::of($l['discount_amount']) : '0',
            'vat_rate' => $dec($l['vat_rate'] ?? '0'),
            'withholding_tenths' => (int) ($l['withholding_tenths'] ?? 0),
        ];
    }

    private function filtered(Request $request): Builder
    {
        $query = Invoice::query()->select('invoices.*')->with(['student:id,full_name,student_no']);
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn (Builder $w) => $w->where('invoices.invoice_no', 'like', strtoupper($q).'%')->orWhere('invoices.buyer_name', 'like', "%{$q}%")
                ->orWhereIn('invoices.student_id', \App\Models\Student::query()->withTrashed()->where('full_name', 'like', "%{$q}%")->select('id')));
        }
        foreach (['status', 'kind', 'document_type'] as $f) {
            if ($v = $request->query($f)) {
                $query->where("invoices.{$f}", $v);
            }
        }
        if ($s = $request->integer('student_id')) {
            $query->where('invoices.student_id', $s);
        }
        if ($from = $request->date('from')) {
            $query->where('invoices.issue_date', '>=', $from->toDateString());
        }
        if ($to = $request->date('to')) {
            $query->where('invoices.issue_date', '<=', $to->toDateString());
        }

        return $query;
    }

    private function row(Invoice $i, Request $request): array
    {
        return [
            'id' => $i->id, 'label' => $i->label(), 'invoice_no' => $i->invoice_no, 'kind' => $i->kind, 'status' => $i->status, 'document_type' => $i->document_type,
            'issue_date' => $i->issue_date->toDateString(), 'buyer_type' => $i->buyer_type, 'buyer_id' => $i->buyer_id, 'buyer_name' => $i->buyer_name,
            'buyer_tax_id_masked' => $i->buyer_tax_id_last4 ? Sensitive::maskNationalId($i->buyer_tax_id_last4) : null,
            'student' => $i->student ? ['id' => $i->student->id, 'full_name' => $i->student->full_name, 'student_no' => $i->student->student_no] : null,
            'net_total' => (string) $i->net_total, 'vat_total' => (string) $i->vat_total, 'withholding_total' => (string) $i->withholding_total,
            'payable_total' => (string) $i->payable_total, 'integrator_status' => $i->integrator_status, 'related_invoice_id' => $i->related_invoice_id,
        ];
    }

    private function dec(mixed $v): string
    {
        return bcadd((string) ($v ?? '0'), '0', 2);
    }
}
