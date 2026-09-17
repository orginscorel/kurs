<?php

namespace App\Services\Accounting;

use App\Models\AccountTransfer;
use App\Models\FinanceEntry;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finans kaynaklarından otomatik yevmiye fişi. Hesap mantığı:
 *  - Tahsilat:   B kasa/banka/POS  /  A 340 Alınan sipariş avansları (henüz faturalanmamış)
 *  - Fatura:     B 340 (bağlı tahsilat kadar) + B 120 (kalan)  /  A 600 matrah + A 391 KDV (tevkifat düşülmüş)
 *  - Sonradan bağlanan tahsilat: B 340 / A 120
 *  - İade faturası: B 610 matrah + B 391  /  A 120
 *  - Nakit iade: B 340 (faturasız kısım) + B 120 (faturalı kısım)  /  A kasa/banka
 *  - Gelir: B hesap / A kategori kodu;  Gider: B kategori kodu / A hesap
 *  - Transfer: B hedef / A kaynak;  Açılış: B hesap / A 500;  Sayım farkı: 649 / 659
 *  - İptaller: kaynağın tüm fişleri tek ters fişle kapanır.
 */
class AccountingPoster
{
    private static ?bool $ready = null;

    public function __construct(
        private readonly JournalService $journal,
        private readonly ChartOfAccounts $chart,
    ) {}

    /** Tablolar kurulu ve kurum ayarında otomatik fiş açık mı? */
    public static function enabled(?int $branchId = null): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('journal_entries') && Schema::hasTable('ledger_accounts');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        if (! self::$ready) {
            return false;
        }

        return filter_var(Settings::get('accounting.auto_journal', true, $branchId), FILTER_VALIDATE_BOOLEAN);
    }

    public static function resetState(): void
    {
        self::$ready = null;
    }

    // ------------------------------------------------------------------ tahsilat

    public function payment(Payment $p): ?JournalEntry
    {
        $student = DB::table('students')->where('id', $p->student_id)->value('full_name');
        $amount = (string) $p->amount;
        $partner = ['partner_type' => 'student', 'partner_id' => $p->student_id, 'partner_name' => $student];

        return $this->journal->post($p->branch_id, $p->paid_at, "Tahsilat {$p->receipt_no} — {$student}", 'payment', $p->id, 'payment', [
            ['code' => $this->chart->accountCode($p->branch_id, $p->finance_account_id), 'debit' => $amount, 'description' => "Tahsilat {$p->receipt_no}", 'finance_account_id' => $p->finance_account_id] + $partner,
            ['code' => $this->chart->roleCode($p->branch_id, 'advance'), 'credit' => $amount, 'description' => "Tahsilat {$p->receipt_no}"] + $partner,
        ]);
    }

    public function paymentVoid(Payment $p): ?JournalEntry
    {
        return $this->journal->reverseSource($p->branch_id, 'payment', $p->id, 'payment_void', $p->voided_at ?? now(), "İptal: tahsilat {$p->receipt_no}");
    }

    // ------------------------------------------------------------------ gelir / gider

    public function entry(FinanceEntry $e): ?JournalEntry
    {
        $amount = (string) $e->amount;
        $accountCode = $this->chart->accountCode($e->branch_id, $e->finance_account_id);
        $categoryCode = $this->chart->categoryCode($e->branch_id, $e->finance_category_id);
        $partner = $e->counterparty ? ['partner_type' => 'other', 'partner_name' => $e->counterparty] : [];
        $desc = mb_substr($e->description, 0, 200).($e->document_no ? " (belge {$e->document_no})" : '');
        $lines = $e->direction === 'income'
            ? [
                ['code' => $accountCode, 'debit' => $amount, 'description' => $desc, 'finance_account_id' => $e->finance_account_id],
                ['code' => $categoryCode, 'credit' => $amount, 'description' => $desc] + $partner,
            ]
            : [
                ['code' => $categoryCode, 'debit' => $amount, 'description' => $desc] + $partner,
                ['code' => $accountCode, 'credit' => $amount, 'description' => $desc, 'finance_account_id' => $e->finance_account_id],
            ];

        return $this->journal->post($e->branch_id, $e->entry_date, ($e->direction === 'income' ? 'Gelir: ' : 'Gider: ').$desc, 'finance_entry', $e->id, 'entry', $lines);
    }

    public function entryVoid(FinanceEntry $e): ?JournalEntry
    {
        return $this->journal->reverseSource($e->branch_id, 'finance_entry', $e->id, 'entry_void', $e->voided_at ?? now(), 'İptal: '.mb_substr($e->description, 0, 200));
    }

    // ------------------------------------------------------------------ transfer / açılış / sayım

    public function transfer(AccountTransfer $t): ?JournalEntry
    {
        $amount = (string) $t->amount;
        $b = $t->branch_id;
        $kind = $t->kind ?? 'transfer';
        $desc = $t->description ?: match ($kind) { 'opening' => 'Açılış bakiyesi', 'adjustment' => 'Sayım farkı', default => 'Hesaplar arası transfer' };
        $toCode = $this->chart->accountCode($b, $t->to_account_id);

        $lines = match ($kind) {
            'opening' => [
                ['code' => $toCode, 'debit' => $amount, 'description' => $desc, 'finance_account_id' => $t->to_account_id],
                ['code' => $this->chart->roleCode($b, 'opening_equity'), 'credit' => $amount, 'description' => $desc],
            ],
            'adjustment' => [
                ['code' => $toCode, 'debit' => $amount, 'description' => $desc, 'finance_account_id' => $t->to_account_id],
                ['code' => $this->chart->roleCode($b, bccomp($amount, '0', 2) > 0 ? 'count_gain' : 'count_loss'), 'credit' => $amount, 'description' => $desc],
            ],
            default => [
                ['code' => $toCode, 'debit' => $amount, 'description' => $desc, 'finance_account_id' => $t->to_account_id],
                ['code' => $this->chart->accountCode($b, (int) $t->from_account_id), 'credit' => $amount, 'description' => $desc, 'finance_account_id' => $t->from_account_id],
            ],
        };

        return $this->journal->post($b, $t->transfer_date, $desc, 'account_transfer', $t->id, $kind === 'transfer' ? 'transfer' : $kind, $lines);
    }

    public function transferVoid(AccountTransfer $t): ?JournalEntry
    {
        return $this->journal->reverseSource($t->branch_id, 'account_transfer', $t->id, 'transfer_void', $t->voided_at ?? now(), 'İptal: '.($t->description ?: 'transfer'));
    }

    // ------------------------------------------------------------------ fatura

    /** @param string $linkedAmount faturaya bağlı (faturalanan) tahsilat toplamı */
    public function invoiceIssued(Invoice $i, string $linkedAmount): JournalEntry
    {
        $b = $i->branch_id;
        $partner = $this->invoicePartner($i);
        $net = (string) $i->net_total;
        $vat = bcsub((string) $i->vat_total, (string) $i->withholding_total, 2);
        $payable = (string) $i->payable_total;
        $desc = ($i->kind === 'return' ? 'İade faturası ' : 'Fatura ').$i->invoice_no.' — '.$i->buyer_name;

        if ($i->kind === 'return') {
            $lines = [
                ['code' => $this->chart->roleCode($b, 'sales_return'), 'debit' => $net, 'description' => $desc] + $partner,
                ['code' => $this->chart->roleCode($b, 'vat_output'), 'debit' => $vat, 'description' => 'KDV — '.$i->invoice_no],
                ['code' => $this->chart->roleCode($b, 'receivable'), 'credit' => $payable, 'description' => $desc] + $partner,
            ];
        } else {
            $linked = Dec::min($linkedAmount, $payable);
            $lines = [
                ['code' => $this->chart->roleCode($b, 'advance'), 'debit' => $linked, 'description' => $desc.' (tahsil edilmiş kısım)'] + $partner,
                ['code' => $this->chart->roleCode($b, 'receivable'), 'debit' => bcsub($payable, $linked, 2), 'description' => $desc] + $partner,
                ['code' => $this->chart->roleCode($b, 'sales'), 'credit' => $net, 'description' => $desc] + $partner,
                ['code' => $this->chart->roleCode($b, 'vat_output'), 'credit' => $vat, 'description' => 'KDV — '.$i->invoice_no],
            ];
        }

        return $this->journal->post($b, $i->issue_date, $desc, 'invoice', $i->id, 'invoice', $lines);
    }

    public function invoicePaymentLinked(Invoice $i, int $linkId, string $amount, string $receiptNo): JournalEntry
    {
        $b = $i->branch_id;
        $partner = $this->invoicePartner($i);
        $desc = "Mahsup: {$receiptNo} → {$i->invoice_no}";

        return $this->journal->post($b, CarbonImmutable::today(), $desc, 'invoice_payment', $linkId, 'invoice_link', [
            ['code' => $this->chart->roleCode($b, 'advance'), 'debit' => $amount, 'description' => $desc] + $partner,
            ['code' => $this->chart->roleCode($b, 'receivable'), 'credit' => $amount, 'description' => $desc] + $partner,
        ]);
    }

    /** İptal: fatura fişi ve sonradan yapılan tüm mahsuplar ters çevrilir. */
    public function invoiceCancelled(Invoice $i): void
    {
        $date = CarbonImmutable::today();
        $this->journal->reverseSource($i->branch_id, 'invoice', $i->id, 'invoice_cancel', $date, "İptal: fatura {$i->invoice_no}");
        foreach (DB::table('invoice_payments')->where('invoice_id', $i->id)->pluck('id') as $linkId) {
            $this->journal->reverseSource($i->branch_id, 'invoice_payment', (int) $linkId, 'invoice_cancel', $date, "İptal: fatura {$i->invoice_no} mahsubu");
        }
    }

    // ------------------------------------------------------------------ iade

    public function refund(Refund $r): JournalEntry
    {
        $b = $r->branch_id;
        $student = DB::table('students')->where('id', $r->student_id)->value('full_name');
        $partner = ['partner_type' => 'student', 'partner_id' => $r->student_id, 'partner_name' => $student];
        $amount = (string) $r->amount;
        $invoiced = Dec::min((string) $r->invoiced_portion, $amount);
        $desc = "İade {$r->refund_no} — {$student}";

        return $this->journal->post($b, $r->refunded_at, $desc, 'refund', $r->id, 'refund', [
            ['code' => $this->chart->roleCode($b, 'advance'), 'debit' => bcsub($amount, $invoiced, 2), 'description' => $desc] + $partner,
            ['code' => $this->chart->roleCode($b, 'receivable'), 'debit' => $invoiced, 'description' => $desc.' (faturalı kısım)'] + $partner,
            ['code' => $this->chart->accountCode($b, $r->finance_account_id), 'credit' => $amount, 'description' => $desc, 'finance_account_id' => $r->finance_account_id],
        ]);
    }

    public function refundVoid(Refund $r): ?JournalEntry
    {
        return $this->journal->reverseSource($r->branch_id, 'refund', $r->id, 'refund_void', $r->voided_at ?? now(), "İptal: iade {$r->refund_no}");
    }

    private function invoicePartner(Invoice $i): array
    {
        return $i->student_id
            ? ['partner_type' => 'student', 'partner_id' => $i->student_id, 'partner_name' => DB::table('students')->where('id', $i->student_id)->value('full_name') ?? $i->buyer_name]
            : ['partner_type' => $i->buyer_type === 'guardian' ? 'guardian' : 'other', 'partner_id' => $i->buyer_id, 'partner_name' => $i->buyer_name];
    }
}
