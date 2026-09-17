<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\AccountTransfer;
use App\Models\FinanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Invoicing\InvoiceMath;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\Integrators\IntegratorManager;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kurumsal finans belgeleri (tek ya da toplu tek PDF): tahsilat makbuzu, fatura, iade makbuzu,
 * işlem dekontu, cari ekstre, analiz raporu. Hepsi pdf.finance.document sarmalayıcısını kullanır.
 */
class FinanceExtraDocuments
{
    public const BULK_MAX = 200;

    public const VOUCHER_TYPES = ['payment', 'refund', 'finance_entry', 'account_transfer'];

    public function __construct(private readonly FinanceDocuments $base) {}

    // ------------------------------------------------------------------ makbuz

    /** @param Collection<int, Payment> $payments */
    public function receipts(Collection $payments, bool $inline = false): Response
    {
        $this->guardSize($payments);
        $payments = new EloquentCollection($payments->all());
        $payments->loadMissing(['student', 'account', 'receiver', 'enrollment.program', 'enrollment.term', 'allocations.installment']);
        $guardians = DB::table('guardians')->whereIn('id', $payments->pluck('guardian_id')->filter())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $voiders = User::query()->whereIn('id', $payments->pluck('voided_by')->filter())->pluck('name', 'id');
        $docs = $payments->map(function (Payment $p) use ($guardians, $voiders) {
            $g = $p->guardian_id ? ($guardians[$p->guardian_id] ?? null) : null;

            return [
                'part' => 'receipt',
                'header' => ['title' => 'Tahsilat Makbuzu', 'no' => $p->receipt_no, 'meta' => $p->paid_at->format('d.m.Y H:i')],
                'stamp' => $p->voided_at ? ['text' => 'İPTAL EDİLDİ', 'tone' => 'red'] : null,
                'data' => [
                    'payment' => $p,
                    'methodLabel' => Payment::METHODS[$p->method] ?? $p->method,
                    'amountWords' => AmountInWords::lira((string) $p->amount),
                    'payer' => $p->payer_name ?: ($g ? trim($g->first_name.' '.$g->last_name) : $p->student?->full_name),
                    'voider' => $p->voided_by ? ($voiders[$p->voided_by] ?? null) : null,
                ],
            ];
        })->values()->all();
        $one = $payments->count() === 1;
        $name = $one ? "makbuz-{$payments->first()->receipt_no}.pdf" : 'makbuzlar-'.now()->format('Y-m-d-His').'.pdf';

        return $this->render($docs, $one ? 'Makbuz '.$payments->first()->receipt_no : 'Tahsilat makbuzları', $name, $inline, 'a5');
    }

    // ------------------------------------------------------------------ fatura

    public function invoice(Invoice $invoice, bool $inline = false): Response
    {
        return $this->invoices(collect([$invoice]), $inline);
    }

    /** @param Collection<int, Invoice> $invoices */
    public function invoices(Collection $invoices, bool $inline = false): Response
    {
        $this->guardSize($invoices);
        $invoices = new EloquentCollection($invoices->all());
        $invoices->loadMissing(['lines', 'payments', 'related', 'student']);
        $integrator = app(IntegratorManager::class)->current();
        $docs = $invoices->map(function (Invoice $invoice) use ($integrator) {
            $lines = $invoice->lines->map(fn ($l) => ['vat_rate' => (string) $l->vat_rate, 'net_amount' => (string) $l->net_amount, 'vat_amount' => (string) $l->vat_amount,
                'gross_amount' => (string) $l->gross_amount, 'discount_amount' => (string) $l->discount_amount, 'withholding_amount' => (string) $l->withholding_amount, 'total_amount' => (string) $l->total_amount])->all();
            $typeLabel = $invoice->kind === 'return' ? 'İade Faturası' : ($invoice->document_type === 'paper' ? 'Fatura' : (Invoice::DOCUMENT_TYPES[$invoice->document_type] ?? '').' Faturası');

            return [
                'part' => 'invoice',
                'header' => ['title' => $typeLabel, 'no' => $invoice->invoice_no ?? ('TASLAK #'.$invoice->id), 'meta' => 'Düzenleme tarihi: '.$invoice->issue_date->format('d.m.Y')],
                'stamp' => $invoice->status === 'cancelled' ? ['text' => 'İPTAL EDİLDİ', 'tone' => 'red'] : ($invoice->status === 'draft' ? ['text' => 'TASLAK', 'tone' => 'gray'] : null),
                'data' => [
                    'invoice' => $invoice,
                    'taxId' => $invoice->buyer_tax_id,
                    'breakdown' => InvoiceMath::totals($lines)['vat_breakdown'],
                    'hasWithholding' => bccomp((string) $invoice->withholding_total, '0', 2) > 0,
                    'amountWords' => InvoiceService::amountWords($invoice),
                    'payments' => $invoice->payments,
                    'studentLine' => $invoice->student ? $invoice->student->full_name.' (No '.$invoice->student->student_no.')' : null,
                    'integratorNote' => $integrator->connected()
                        ? 'Bu fatura '.$integrator->label().' üzerinden iletilmiştir.'
                        : 'Bilgi: e-Fatura/e-Arşiv entegratörü bağlı değildir; bu belge kurum içi fatura çıktısıdır ve GİB\'e iletilmemiştir.',
                ],
            ];
        })->values()->all();
        $first = $invoices->first();
        $one = $invoices->count() === 1;
        $name = $one ? 'fatura-'.($first->invoice_no ?? 'taslak-'.$first->id).'.pdf' : 'faturalar-'.now()->format('Y-m-d-His').'.pdf';

        return $this->render($docs, $one ? 'Fatura '.($first->invoice_no ?? 'taslak') : 'Faturalar', $name, $inline);
    }

    // ------------------------------------------------------------------ iade

    public function refund(Refund $refund, bool $inline = false): Response
    {
        $refund->loadMissing(['student', 'payment', 'account', 'creator', 'allocations.installment']);

        return $this->render([[
            'part' => 'refund',
            'header' => ['title' => 'İade Makbuzu', 'no' => $refund->refund_no, 'meta' => $refund->refunded_at->format('d.m.Y H:i')],
            'stamp' => $refund->voided_at ? ['text' => 'İPTAL EDİLDİ', 'tone' => 'red'] : null,
            'data' => ['refund' => $refund, 'amountWords' => AmountInWords::lira((string) $refund->amount)],
        ]], 'İade makbuzu '.$refund->refund_no, "iade-{$refund->refund_no}.pdf", $inline, 'a5');
    }

    // ------------------------------------------------------------------ işlem dekontu

    public function voucher(string $type, int $id, bool $inline = false): Response
    {
        $v = $this->voucherData($type, $id);

        return $this->render([[
            'part' => 'voucher',
            'header' => ['title' => 'İşlem Dekontu', 'no' => $v['no'], 'meta' => $v['date']],
            'stamp' => $v['voided'] ? ['text' => 'İPTAL EDİLDİ', 'tone' => 'red'] : null,
            'data' => ['v' => $v],
        ]], 'Dekont '.$v['no'], 'dekont-'.$v['slug'].'.pdf', $inline, 'a5');
    }

    /** @return array<string, mixed> */
    public function voucherData(string $type, int $id): array
    {
        $journal = fn (string $st) => DB::table('journal_entries')->where('source_type', $st)->where('source_id', $id)->orderBy('id')->pluck('entry_no')->join(', ');
        $user = fn (?int $uid) => $uid ? User::query()->whereKey($uid)->value('name') : null;

        switch ($type) {
            case 'payment':
                $p = Payment::query()->with(['student', 'account'])->findOrFail($id);

                return [
                    'no' => $p->receipt_no, 'slug' => $p->receipt_no, 'date' => $p->paid_at->format('d.m.Y H:i'),
                    'kind' => 'Tahsilat ('.(Payment::METHODS[$p->method] ?? $p->method).')', 'direction_label' => 'HESABA GİREN',
                    'from_label' => 'Ödeyen', 'from' => ($p->payer_name ?: $p->student?->full_name).' — öğrenci '.$p->student?->full_name,
                    'to_label' => 'Giriş yapılan hesap', 'to' => $p->account?->name,
                    'rows' => array_filter(['Referans' => $p->reference, 'Öğrenci no' => $p->student?->student_no]),
                    'amount' => (string) $p->amount, 'words' => AmountInWords::lira((string) $p->amount), 'description' => $p->note,
                    'by' => $user($p->received_by), 'counter_sign' => 'Ödeyen', 'counter_name' => $p->payer_name,
                    'voided' => $p->voided_at?->format('d.m.Y H:i'), 'void_reason' => $p->void_reason, 'journal' => $journal('payment'),
                ];
            case 'refund':
                $r = Refund::query()->with(['student', 'account', 'payment'])->findOrFail($id);

                return [
                    'no' => $r->refund_no, 'slug' => $r->refund_no, 'date' => $r->refunded_at->format('d.m.Y H:i'),
                    'kind' => 'İade ödemesi ('.(Payment::METHODS[$r->method] ?? $r->method).')', 'direction_label' => 'HESAPTAN ÇIKAN',
                    'from_label' => 'Çıkış yapılan hesap', 'from' => $r->account?->name,
                    'to_label' => 'İade alan', 'to' => ($r->payee_name ?: '—').' — öğrenci '.$r->student?->full_name,
                    'rows' => array_filter(['İlgili makbuz' => $r->payment?->receipt_no, 'Referans' => $r->reference]),
                    'amount' => (string) $r->amount, 'words' => AmountInWords::lira((string) $r->amount), 'description' => $r->reason,
                    'by' => $user($r->created_by), 'counter_sign' => 'Teslim alan', 'counter_name' => $r->payee_name,
                    'voided' => $r->voided_at?->format('d.m.Y H:i'), 'void_reason' => $r->void_reason, 'journal' => $journal('refund'),
                ];
            case 'finance_entry':
                $e = FinanceEntry::query()->with(['category', 'account'])->findOrFail($id);
                $in = $e->direction === 'income';

                return [
                    'no' => 'GG-'.str_pad((string) $e->id, 6, '0', STR_PAD_LEFT), 'slug' => 'gg-'.$e->id, 'date' => $e->entry_date->format('d.m.Y'),
                    'kind' => ($in ? 'Gelir' : 'Gider / ödeme').' — '.$e->category?->name, 'direction_label' => $in ? 'HESABA GİREN' : 'HESAPTAN ÇIKAN',
                    'from_label' => $in ? 'Karşı taraf' : 'Çıkış yapılan hesap', 'from' => $in ? ($e->counterparty ?: '—') : $e->account?->name,
                    'to_label' => $in ? 'Giriş yapılan hesap' : 'Ödenen (karşı taraf)', 'to' => $in ? $e->account?->name : ($e->counterparty ?: '—'),
                    'rows' => array_filter(['Belge no' => $e->document_no]),
                    'amount' => (string) $e->amount, 'words' => AmountInWords::lira((string) $e->amount), 'description' => $e->description,
                    'by' => $user($e->created_by), 'counter_sign' => $in ? 'Teslim eden' : 'Teslim alan', 'counter_name' => $e->counterparty,
                    'voided' => $e->voided_at?->format('d.m.Y H:i'), 'void_reason' => $e->void_reason, 'journal' => $journal('finance_entry'),
                ];
            case 'account_transfer':
                $t = AccountTransfer::query()->with(['from', 'to'])->findOrFail($id);
                $kind = AccountService::TRANSFER_KINDS[$t->kind ?? 'transfer'] ?? 'Transfer';
                $negative = str_starts_with((string) $t->amount, '-');
                $amount = ltrim((string) $t->amount, '-');

                return [
                    'no' => 'VRM-'.str_pad((string) $t->id, 6, '0', STR_PAD_LEFT), 'slug' => 'virman-'.$t->id, 'date' => $t->transfer_date->format('d.m.Y'),
                    'kind' => $t->kind === 'transfer' ? 'Virman (hesaplar arası transfer)' : $kind,
                    'direction_label' => $t->kind === 'transfer' ? 'AKTARILAN' : ($negative ? 'HESAPTAN ÇIKAN' : 'HESABA GİREN'),
                    'from_label' => 'Kaynak hesap', 'from' => $t->from?->name ?? '—',
                    'to_label' => 'Hedef hesap', 'to' => $t->to?->name,
                    'rows' => [],
                    'amount' => $amount, 'words' => AmountInWords::lira($amount), 'description' => $t->description,
                    'by' => $user($t->created_by), 'counter_sign' => 'Onaylayan', 'counter_name' => null,
                    'voided' => $t->voided_at?->format('d.m.Y H:i'), 'void_reason' => $t->void_reason, 'journal' => $journal('account_transfer'),
                ];
        }

        throw new BusinessRuleException('Geçersiz dekont türü.', 'invalid_voucher', [], 404);
    }

    // ------------------------------------------------------------------ ekstre / rapor

    public function statement(array $statement, array $holder, ?CarbonImmutable $from, ?CarbonImmutable $to, string $slug, bool $inline = false): Response
    {
        return $this->render([[
            'part' => 'statement',
            'header' => ['title' => 'Cari Hesap Ekstresi', 'no' => $holder['name'], 'meta' => ($from ? $from->format('d.m.Y') : 'Başlangıç').' – '.($to ? $to->format('d.m.Y') : 'bugün ve sonrası')],
            'stamp' => null,
            'data' => ['s' => $statement, 'holder' => $holder, 'from' => $from, 'to' => $to, 'multi' => count($statement['students']) > 1, 'today' => CarbonImmutable::today()->toDateString()],
        ]], 'Cari hesap ekstresi — '.$holder['name'], "cari-ekstre-{$slug}.pdf", $inline);
    }

    public function analytics(array $report, bool $inline = false): Response
    {
        $wide = collect($report['tables'])->max(fn ($t) => count($t['columns'])) > 7;

        return $this->render([[
            'part' => 'analytics',
            'header' => ['title' => 'Finans Raporu', 'no' => $report['title'], 'meta' => CarbonImmutable::parse($report['from'])->format('d.m.Y').' – '.CarbonImmutable::parse($report['to'])->format('d.m.Y')],
            'stamp' => null,
            'data' => ['r' => $report],
        ]], $report['title'], "{$report['key']}-{$report['from']}-{$report['to']}.pdf", $inline, 'a4', $wide ? 'landscape' : 'portrait');
    }

    // ------------------------------------------------------------------

    private function render(array $docs, string $title, string $filename, bool $inline, string $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $pdf = Pdf::loadView('pdf.finance.document', ['institution' => $this->base->institution(), 'docs' => $docs, 'title' => $title])
            ->setPaper($paper, $orientation);

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    private function guardSize(Collection $items): void
    {
        if ($items->isEmpty()) {
            throw new BusinessRuleException('Yazdırılacak belge seçilmedi.', 'nothing_selected');
        }
        if ($items->count() > self::BULK_MAX) {
            throw new BusinessRuleException('Tek seferde en çok '.self::BULK_MAX.' belge yazdırılabilir.', 'too_many_documents');
        }
    }
}
