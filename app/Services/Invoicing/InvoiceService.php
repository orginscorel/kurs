<?php

namespace App\Services\Invoicing;

use App\Exceptions\BusinessRuleException;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Services\Accounting\AccountingPoster;
use App\Services\Accounting\Dec;
use App\Services\Accounting\FinanceSettings;
use App\Services\Accounting\PeriodLock;
use App\Services\Finance\AmountInWords;
use App\Services\Finance\FinanceAudit;
use App\Services\Invoicing\Integrators\IntegratorManager;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Sensitive;
use App\Support\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fatura yaşam döngüsü: taslak → kesildi → iptal. Kesilen fatura değişmez; düzeltme iptal ya da iade faturasıdır.
 * Fatura numarası YALNIZ kesimde verilir (taslaklar numara tüketmez, sıra boşluksuz kalır).
 * GİB/entegratör gönderimi yoktur; IntegratorManager "bağlı değil" ya da simülasyon döndürür.
 */
class InvoiceService
{
    public function __construct(
        private readonly AccountingPoster $poster,
        private readonly PeriodLock $periods,
        private readonly IntegratorManager $integrators,
    ) {}

    // ================================================================== taslak

    /**
     * @param array{kind?:string, document_type?:string, issue_date?:string, buyer_type:string, buyer_id?:?int, buyer_name:string,
     *   buyer_tax_id?:?string, buyer_tax_office?:?string, buyer_address?:?string, buyer_email?:?string, buyer_phone?:?string,
     *   student_id?:?int, enrollment_id?:?int, related_invoice_id?:?int, prices_include_vat?:bool, notes?:?string,
     *   lines: list<array>, payments?: list<array{payment_id:int, amount:string}>, idempotency_key?:?string} $data
     */
    public function saveDraft(array $data, ?Invoice $invoice = null): Invoice
    {
        if ($invoice && $invoice->status !== 'draft') {
            throw new BusinessRuleException('Yalnız taslak fatura düzenlenebilir.', 'invoice_not_draft');
        }
        if (! $invoice && ! empty($data['idempotency_key'])) {
            if ($existing = Invoice::query()->where('idempotency_key', $data['idempotency_key'])->first()) {
                return $existing;
            }
        }
        if (! isset(Invoice::BUYER_TYPES[$data['buyer_type']])) {
            throw new BusinessRuleException('Geçersiz alıcı türü.', 'invalid_buyer_type');
        }
        if (mb_strlen(trim($data['buyer_name'] ?? '')) < 2) {
            throw new BusinessRuleException('Alıcı adını girin.', 'buyer_required');
        }
        $taxId = isset($data['buyer_tax_id']) ? preg_replace('/\D/', '', (string) $data['buyer_tax_id']) : null;
        if ($taxId !== null && $taxId !== '' && ! self::validTaxId($taxId)) {
            throw new BusinessRuleException('TCKN 11 haneli geçerli bir numara ya da VKN 10 haneli olmalı.', 'invalid_tax_id');
        }
        $lines = $data['lines'] ?? [];
        if (count($lines) === 0 || count($lines) > 100) {
            throw new BusinessRuleException('Faturada en az bir kalem olmalı (en çok 100).', 'invoice_lines_required');
        }
        $include = (bool) ($data['prices_include_vat'] ?? true);
        $withholdingAllowed = (bool) FinanceSettings::get('withholding_enabled');
        $computed = [];
        foreach (array_values($lines) as $i => $line) {
            if (mb_strlen(trim($line['description'] ?? '')) < 2) {
                throw new BusinessRuleException(($i + 1).'. kalemin açıklamasını girin.', 'line_description_required');
            }
            if (! $withholdingAllowed && (int) ($line['withholding_tenths'] ?? 0) > 0) {
                throw new BusinessRuleException('Tevkifat ayarlardan kapalı; önce Finans ayarlarından açın.', 'withholding_disabled');
            }
            $computed[] = ['description' => trim($line['description']), 'unit' => mb_substr(trim($line['unit'] ?? 'ADET') ?: 'ADET', 0, 12)] + InvoiceMath::line($line, $include);
        }
        $totals = InvoiceMath::totals($computed);
        $kind = $data['kind'] ?? ($invoice?->kind ?? 'sales');
        if (! isset(Invoice::KINDS[$kind])) {
            throw new BusinessRuleException('Geçersiz fatura türü.', 'invalid_kind');
        }
        $docType = $data['document_type'] ?? FinanceSettings::get('default_document_type');
        if (! isset(Invoice::DOCUMENT_TYPES[$docType])) {
            throw new BusinessRuleException('Geçersiz belge türü.', 'invalid_document_type');
        }
        $issueDate = CarbonImmutable::parse($data['issue_date'] ?? CarbonImmutable::today()->toDateString());

        return DB::transaction(function () use ($data, $invoice, $taxId, $include, $computed, $totals, $kind, $docType, $issueDate) {
            $isNew = ! $invoice;
            $invoice ??= new Invoice();
            if (! $isNew) {
                $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($invoice->status !== 'draft') {
                    throw new BusinessRuleException('Yalnız taslak fatura düzenlenebilir.', 'invoice_not_draft');
                }
            }
            $invoice->fill([
                'kind' => $kind,
                'document_type' => $docType,
                'issue_date' => $issueDate->toDateString(),
                'buyer_type' => $data['buyer_type'],
                'buyer_id' => $data['buyer_id'] ?? null,
                'buyer_name' => mb_substr(trim($data['buyer_name']), 0, 200),
                'buyer_tax_id' => $taxId ?: null,
                'buyer_tax_id_last4' => $taxId ? substr($taxId, -4) : null,
                'buyer_tax_office' => $data['buyer_tax_office'] ?? null,
                'buyer_address' => isset($data['buyer_address']) ? mb_substr((string) $data['buyer_address'], 0, 400) : null,
                'buyer_email' => $data['buyer_email'] ?? null,
                'buyer_phone' => $data['buyer_phone'] ?? null,
                'student_id' => $data['student_id'] ?? null,
                'enrollment_id' => $data['enrollment_id'] ?? null,
                'related_invoice_id' => $data['related_invoice_id'] ?? ($invoice->related_invoice_id ?? null),
                'prices_include_vat' => $include,
                'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 1000) : null,
            ]);
            if ($isNew) {
                $invoice->forceFill(['idempotency_key' => $data['idempotency_key'] ?? null, 'created_by' => Auth::id(), 'status' => 'draft']);
            }
            $invoice->forceFill(collect($totals)->except('vat_breakdown')->all())->save();

            $invoice->lines()->delete();
            foreach ($computed as $i => $line) {
                $invoice->lines()->create(['sequence' => $i + 1] + $line);
            }

            if (array_key_exists('payments', $data)) {
                $this->syncDraftPayments($invoice, $data['payments'] ?? []);
            }

            FinanceAudit::log($isNew ? 'invoice.draft_created' : 'invoice.draft_updated', sprintf(
                '%s için %s TL tutarında fatura taslağı %s.', $invoice->buyer_name, Money::format($invoice->payable_total), $isNew ? 'oluşturdu' : 'güncelledi',
            ), $invoice);

            return $invoice->refresh();
        });
    }

    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new BusinessRuleException('Yalnız taslak fatura silinebilir; kesilmiş faturayı iptal edin.', 'invoice_not_draft');
            }
            DB::table('invoice_payments')->where('invoice_id', $locked->id)->delete();
            $locked->lines()->delete();
            $locked->delete();
            FinanceAudit::log('invoice.draft_deleted', "{$locked->buyer_name} için fatura taslağını (#{$locked->id}) sildi.", null, ['invoice_id' => $locked->id]);
        });
    }

    /** @param list<array{payment_id:int, amount:string}> $links */
    private function syncDraftPayments(Invoice $invoice, array $links): void
    {
        DB::table('invoice_payments')->where('invoice_id', $invoice->id)->delete();
        $sum = '0.00';
        foreach ($links as $link) {
            $payment = Payment::query()->whereKey($link['payment_id'])->lockForUpdate()->first();
            if (! $payment || $payment->voided_at) {
                throw new BusinessRuleException('Bağlanan tahsilat bulunamadı ya da iptal edilmiş.', 'payment_invalid');
            }
            $amount = Dec::round(Money::of($link['amount'] ?? $this->availableForInvoice($payment, $invoice->id)));
            $available = $this->availableForInvoice($payment, $invoice->id);
            if (! Dec::positive($amount) || bccomp($amount, $available, 2) > 0) {
                throw new BusinessRuleException(sprintf('%s makbuzunun faturalanabilir tutarı %s TL.', $payment->receipt_no, Money::format($available)), 'payment_over_linked');
            }
            DB::table('invoice_payments')->insert(['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
            $sum = bcadd($sum, $amount, 2);
        }
        if (bccomp($sum, (string) $invoice->payable_total, 2) > 0) {
            throw new BusinessRuleException('Bağlanan tahsilat toplamı fatura tutarını aşamaz.', 'links_exceed_invoice');
        }
    }

    // ================================================================== kesim / iptal

    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            /** @var Invoice $inv */
            $inv = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($inv->status === 'issued') {
                return $inv; // çift tıklama: aynı sonuç
            }
            if ($inv->status !== 'draft') {
                throw new BusinessRuleException('İptal edilmiş fatura kesilemez.', 'invoice_cancelled');
            }
            if (! Dec::positive((string) $inv->payable_total)) {
                throw new BusinessRuleException('Tutarı sıfır olan fatura kesilemez.', 'invoice_zero');
            }
            $issueDate = CarbonImmutable::parse($inv->issue_date);
            if ($issueDate->gt(CarbonImmutable::today())) {
                throw new BusinessRuleException('İleri tarihli fatura kesilemez.', 'future_date');
            }
            $this->periods->assertOpen($inv->branch_id, $issueDate);

            $prefix = $this->prefixFor($inv->kind, $inv->branch_id);
            $lastDate = Invoice::query()->where('status', '!=', 'draft')->where('invoice_no', 'like', $prefix.'%')->max('issue_date');
            if ($lastDate && $issueDate->lt(CarbonImmutable::parse($lastDate))) {
                throw new BusinessRuleException(sprintf('Fatura tarihi son kesilen faturadan (%s) önce olamaz; numara sırası tarih sırasıyla uyumlu olmalı.', CarbonImmutable::parse($lastDate)->format('d.m.Y')), 'invoice_date_order');
            }

            if ($inv->kind === 'return') {
                $this->assertReturnAllowed($inv);
            }

            // Bağlı tahsilatlar hâlâ geçerli ve yeterli mi?
            $linked = '0.00';
            foreach (DB::table('invoice_payments')->where('invoice_id', $inv->id)->get() as $link) {
                $payment = Payment::query()->whereKey($link->payment_id)->lockForUpdate()->firstOrFail();
                if ($payment->voided_at) {
                    throw new BusinessRuleException("{$payment->receipt_no} makbuzu iptal edilmiş; taslaktan çıkarın.", 'payment_invalid');
                }
                if (bccomp((string) $link->amount, $this->availableForInvoice($payment, $inv->id), 2) > 0) {
                    throw new BusinessRuleException("{$payment->receipt_no} makbuzunun faturalanabilir tutarı değişti; taslağı güncelleyin.", 'payment_over_linked');
                }
                $linked = bcadd($linked, (string) $link->amount, 2);
            }

            $number = str_replace('-', '', Sequence::next('invoice:'.$prefix, $prefix, $inv->branch_id, 9));
            $inv->forceFill([
                'status' => 'issued',
                'invoice_no' => $number,
                'ettn' => (string) Str::uuid(),
                'issued_at' => now(),
                'issued_by' => Auth::id(),
            ])->save();

            if (AccountingPoster::enabled($inv->branch_id)) {
                $this->poster->invoiceIssued($inv, $linked);
            }

            $result = $this->integrators->current($inv->branch_id)->send($inv);
            $inv->forceFill(['integrator_status' => $result['status'], 'ettn' => $result['ettn'] ?? $inv->ettn])->save();

            FinanceAudit::log('invoice.issued', sprintf('%s numaralı %s kesti: %s, %s TL (KDV %s TL). %s', $number,
                $inv->kind === 'return' ? 'iade faturasını' : 'faturayı', $inv->buyer_name, Money::format($inv->payable_total), Money::format($inv->vat_total), $result['message']), $inv);

            return $inv;
        });
    }

    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('İptal gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $inv = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($inv->status === 'cancelled') {
                throw new BusinessRuleException('Bu fatura zaten iptal edilmiş.', 'already_cancelled');
            }
            if ($inv->status !== 'issued') {
                throw new BusinessRuleException('Taslak fatura iptal edilmez; silebilirsiniz.', 'invoice_not_issued');
            }
            if (Invoice::query()->where('related_invoice_id', $inv->id)->where('status', 'issued')->exists()) {
                throw new BusinessRuleException('Bu faturaya kesilmiş iade faturası var; önce iade faturasını iptal edin.', 'invoice_has_returns');
            }
            if (DB::table('refunds')->where('invoiced_portion', '>', 0)->whereNull('voided_at')
                ->whereIn('payment_id', DB::table('invoice_payments')->where('invoice_id', $inv->id)->select('payment_id'))->exists()) {
                throw new BusinessRuleException('Bu faturaya bağlı tahsilattan iade yapılmış; önce iadeyi iptal edin.', 'invoice_has_refunds');
            }

            if (AccountingPoster::enabled($inv->branch_id)) {
                $this->poster->invoiceCancelled($inv);
            }
            $result = $this->integrators->current($inv->branch_id)->cancel($inv);
            $inv->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancel_reason' => mb_substr(trim($reason), 0, 300),
                'integrator_status' => $result['status'],
            ])->save();

            FinanceAudit::log('invoice.cancelled', sprintf('%s numaralı faturayı iptal etti (%s TL). Gerekçe: %s. %s', $inv->invoice_no, Money::format($inv->payable_total), $reason, $result['message']), $inv);

            return $inv;
        });
    }

    /** İade faturası taslağı: asıl faturanın kalemleriyle (miktar/tutar düzenlenebilir). */
    public function createReturnDraft(Invoice $original, ?string $idempotencyKey = null): Invoice
    {
        if ($original->status !== 'issued' || $original->kind !== 'sales') {
            throw new BusinessRuleException('İade faturası yalnız kesilmiş satış faturasından oluşturulur.', 'invalid_return_source');
        }
        $original->loadMissing('lines');

        return $this->saveDraft([
            'kind' => 'return',
            'document_type' => $original->document_type,
            'issue_date' => CarbonImmutable::today()->toDateString(),
            'buyer_type' => $original->buyer_type,
            'buyer_id' => $original->buyer_id,
            'buyer_name' => $original->buyer_name,
            'buyer_tax_id' => $original->buyer_tax_id,
            'buyer_tax_office' => $original->buyer_tax_office,
            'buyer_address' => $original->buyer_address,
            'buyer_email' => $original->buyer_email,
            'buyer_phone' => $original->buyer_phone,
            'student_id' => $original->student_id,
            'enrollment_id' => $original->enrollment_id,
            'related_invoice_id' => $original->id,
            'prices_include_vat' => $original->prices_include_vat,
            'notes' => "{$original->invoice_no} numaralı faturanın iadesidir.",
            'lines' => $original->lines->map(fn ($l) => [
                'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit' => $l->unit, 'unit_price' => (string) $l->unit_price,
                'discount_rate' => (string) $l->discount_rate, 'discount_amount' => (string) $l->discount_amount, 'vat_rate' => (string) $l->vat_rate, 'withholding_tenths' => $l->withholding_tenths,
            ])->all(),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function assertReturnAllowed(Invoice $return): void
    {
        $original = $return->related_invoice_id ? Invoice::query()->whereKey($return->related_invoice_id)->lockForUpdate()->first() : null;
        if (! $original || $original->status !== 'issued') {
            throw new BusinessRuleException('İade faturasının bağlı olduğu fatura kesilmiş durumda olmalı.', 'invalid_return_source');
        }
        $already = (string) Invoice::query()->where('related_invoice_id', $original->id)->where('status', 'issued')->sum('net_total');
        $total = bcadd(Money::of($already), (string) $return->net_total, 2);
        if (bccomp($total, (string) $original->net_total, 2) > 0) {
            throw new BusinessRuleException(sprintf('İade toplamı asıl fatura matrahını (%s TL) aşamaz; daha önce %s TL iade edilmiş.', Money::format($original->net_total), Money::format($already)), 'return_exceeds_original');
        }
    }

    // ================================================================== tahsilat bağlantısı

    /**
     * Tahsilatın faturalanabilir kalan tutarı: tutar − (iptal edilmemiş faturalara bağlı tutar) − (geçerli iadeler).
     * $exceptInvoiceId: düzenlenen taslağın kendi bağlantısı hariç tutulur.
     */
    public function availableForInvoice(Payment $payment, ?int $exceptInvoiceId = null): string
    {
        if ($payment->voided_at) {
            return '0.00';
        }
        $linked = (string) DB::table('invoice_payments as ip')->join('invoices as i', 'i.id', '=', 'ip.invoice_id')
            ->where('ip.payment_id', $payment->id)->where('i.status', '!=', 'cancelled')->where('i.kind', 'sales')
            ->when($exceptInvoiceId, fn ($q) => $q->where('i.id', '!=', $exceptInvoiceId))->sum('ip.amount');
        $refunded = (string) DB::table('refunds')->where('payment_id', $payment->id)->whereNull('voided_at')->selectRaw('COALESCE(SUM(amount - invoiced_portion), 0) AS s')->value('s');
        $left = bcsub(bcsub((string) $payment->amount, Money::of($linked), 2), Money::of($refunded), 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    /** Kesilmiş faturaya sonradan tahsilat bağlama (340 → 120 mahsup fişi). */
    public function linkPayment(Invoice $invoice, Payment $payment, ?string $amount = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $payment, $amount) {
            $inv = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $pay = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($inv->kind !== 'sales' || $inv->status === 'cancelled') {
                throw new BusinessRuleException('Tahsilat yalnız geçerli satış faturasına bağlanır.', 'invoice_not_linkable');
            }
            if (DB::table('invoice_payments')->where('invoice_id', $inv->id)->where('payment_id', $pay->id)->exists()) {
                throw new BusinessRuleException('Bu tahsilat bu faturaya zaten bağlı.', 'already_linked');
            }
            $available = $this->availableForInvoice($pay);
            $open = bcsub((string) $inv->payable_total, Money::of((string) DB::table('invoice_payments')->where('invoice_id', $inv->id)->sum('amount')), 2);
            $amount = $amount !== null ? Dec::round(Money::of($amount)) : Dec::min($available, $open);
            if (! Dec::positive($amount) || bccomp($amount, $available, 2) > 0 || bccomp($amount, $open, 2) > 0) {
                throw new BusinessRuleException(sprintf('Bağlanabilir tutar en çok %s TL (tahsilat %s TL, fatura açığı %s TL).', Money::format(Dec::min($available, $open)), Money::format($available), Money::format($open)), 'link_amount_invalid');
            }
            $linkId = DB::table('invoice_payments')->insertGetId(['invoice_id' => $inv->id, 'payment_id' => $pay->id, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
            if ($inv->status === 'issued' && AccountingPoster::enabled($inv->branch_id)) {
                $this->poster->invoicePaymentLinked($inv, (int) $linkId, $amount, $pay->receipt_no);
            }
            FinanceAudit::log('invoice.payment_linked', sprintf('%s makbuzunu (%s TL) %s faturasına bağladı.', $pay->receipt_no, Money::format($amount), $inv->label()), $inv);

            return $inv;
        });
    }

    /** Faturalanmamış tahsilatlar sorgusu (kalan > 0). */
    public function unbilledQuery(int $branchId)
    {
        $linked = DB::table('invoice_payments as ip')->join('invoices as i', 'i.id', '=', 'ip.invoice_id')
            ->where('i.status', '!=', 'cancelled')->where('i.kind', 'sales')->groupBy('ip.payment_id')->selectRaw('ip.payment_id, SUM(ip.amount) AS linked');
        $refunded = DB::table('refunds')->whereNull('voided_at')->groupBy('payment_id')->selectRaw('payment_id, SUM(amount - invoiced_portion) AS refunded');

        return DB::table('payments as p')
            ->join('students as s', 's.id', '=', 'p.student_id')
            ->leftJoinSub($linked, 'l', 'l.payment_id', '=', 'p.id')
            ->leftJoinSub($refunded, 'r', 'r.payment_id', '=', 'p.id')
            ->leftJoin('enrollments as e', 'e.id', '=', 'p.enrollment_id')
            ->leftJoin('programs as pr', 'pr.id', '=', 'e.program_id')
            ->where('p.branch_id', $branchId)->whereNull('p.voided_at')
            ->whereRaw('(p.amount - COALESCE(l.linked, 0) - COALESCE(r.refunded, 0)) > 0');
    }

    /**
     * Toplu taslak: seçilen faturalanmamış tahsilatlardan taslak faturalar.
     * group=payment → her tahsilata bir fatura; group=student → öğrenci başına tek fatura.
     *
     * @param list<int> $paymentIds
     * @return list<int> oluşturulan fatura id'leri
     */
    public function draftsFromPayments(array $paymentIds, string $group = 'payment'): array
    {
        $payments = Payment::query()->whereIn('id', array_unique($paymentIds))->whereNull('voided_at')
            ->with(['student', 'enrollment.program', 'enrollment.term'])->orderBy('paid_at')->get();
        $groups = $group === 'student' ? $payments->groupBy('student_id') : $payments->map(fn ($p) => collect([$p]));
        $rate = (string) FinanceSettings::get('default_vat_rate');
        $service = (string) FinanceSettings::get('service_description');
        $unit = (string) FinanceSettings::get('default_unit');
        $ids = [];

        foreach ($groups as $items) {
            /** @var Collection<int, Payment> $items */
            $lines = [];
            $links = [];
            foreach ($items as $p) {
                $available = $this->availableForInvoice($p);
                if (! Dec::positive($available)) {
                    continue;
                }
                $program = $p->enrollment?->program?->name;
                $term = $p->enrollment?->term?->name;
                $lines[] = InvoiceMath::singleLineFromTotal($available, $rate, trim(($program ? "{$program} " : '').mb_strtolower($service).($term ? " ({$term})" : '')." — makbuz {$p->receipt_no}"), $unit);
                $links[] = ['payment_id' => $p->id, 'amount' => $available];
            }
            if (! $lines) {
                continue;
            }
            $first = $items->first();
            $buyer = $this->buyerFor($first->student, $first->guardian_id);
            $invoice = $this->saveDraft($buyer + [
                'student_id' => $first->student_id,
                'enrollment_id' => $first->enrollment_id,
                'issue_date' => CarbonImmutable::today()->toDateString(),
                'prices_include_vat' => true,
                'lines' => $lines,
                'payments' => $links,
                'notes' => FinanceSettings::get('invoice_note'),
            ]);
            $ids[] = $invoice->id;
        }

        if ($ids) {
            Audit::log('invoice.bulk_drafts', sprintf('%d tahsilattan %d fatura taslağı oluşturdu.', $payments->count(), count($ids)));
        }

        return $ids;
    }

    /** Kayıt / sözleşmeden taslak: liste fiyatı, indirim+burs iskonto olarak. */
    public function draftFromEnrollment(Enrollment $enrollment): Invoice
    {
        $enrollment->loadMissing(['student', 'program', 'term', 'package']);
        $discount = bcadd((string) $enrollment->discount_amount, (string) $enrollment->scholarship_amount, 2);
        $service = (string) FinanceSettings::get('service_description');
        $buyer = $this->buyerFor($enrollment->student, $enrollment->financial_guardian_id);

        return $this->saveDraft($buyer + [
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'issue_date' => CarbonImmutable::today()->toDateString(),
            'prices_include_vat' => true,
            'notes' => trim("Kayıt no {$enrollment->enrollment_no}. ".(FinanceSettings::get('invoice_note') ?? '')),
            'lines' => [[
                'description' => trim(($enrollment->package?->name ?? $enrollment->program?->name ?? 'Eğitim').' '.mb_strtolower($service).($enrollment->term ? " ({$enrollment->term->name})" : '')),
                'quantity' => '1', 'unit' => (string) FinanceSettings::get('default_unit'),
                'unit_price' => (string) $enrollment->list_price,
                'discount_amount' => $discount,
                'vat_rate' => (string) FinanceSettings::get('default_vat_rate'),
            ]],
        ]);
    }

    /** Alıcı bilgisi: ödeme sorumlusu veli (varsa) yoksa öğrenci. */
    public function buyerFor(?Student $student, ?int $guardianId = null): array
    {
        $guardian = null;
        if ($guardianId) {
            $guardian = Guardian::query()->find($guardianId);
        }
        if (! $guardian && $student) {
            $guardian = $student->guardians()->orderByDesc('guardian_student.is_financially_responsible')->orderByDesc('guardian_student.is_primary')->first();
        }
        if ($guardian) {
            return [
                'buyer_type' => 'guardian', 'buyer_id' => $guardian->id, 'buyer_name' => trim($guardian->first_name.' '.$guardian->last_name),
                'buyer_tax_id' => Sensitive::decrypt($guardian->national_id_encrypted), 'buyer_address' => $guardian->address,
                'buyer_email' => $guardian->email, 'buyer_phone' => $guardian->phone,
            ];
        }

        return [
            'buyer_type' => 'student', 'buyer_id' => $student?->id, 'buyer_name' => $student?->full_name ?? 'Alıcı',
            'buyer_tax_id' => $student ? Sensitive::decrypt($student->national_id_encrypted) : null, 'buyer_address' => $student?->address,
            'buyer_email' => $student?->email, 'buyer_phone' => $student?->phone,
        ];
    }

    public function prefixFor(string $kind, ?int $branchId = null): string
    {
        $prefix = strtoupper((string) FinanceSettings::get($kind === 'return' ? 'return_prefix' : 'invoice_prefix', $branchId));

        return preg_match('/^[A-Z0-9]{3}$/', $prefix) ? $prefix : ($kind === 'return' ? 'EBI' : 'EBE');
    }

    public static function validTaxId(string $digits): bool
    {
        return (strlen($digits) === 11 && Sensitive::isValidNationalId($digits)) || preg_match('/^\d{10}$/', $digits) === 1;
    }

    public static function amountWords(Invoice $invoice): string
    {
        return AmountInWords::lira((string) $invoice->payable_total);
    }
}
