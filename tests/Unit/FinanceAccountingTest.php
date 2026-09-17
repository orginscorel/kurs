<?php

namespace Tests\Unit;

use App\Events\PaymentReceived;
use App\Events\PaymentVoided;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountTransfer;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Installment;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentCardDetail;
use App\Models\Student;
use App\Services\Accounting\AccountingPoster;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\Dec;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\LedgerReports;
use App\Services\Accounting\PeriodLock;
use App\Services\Finance\AccountService;
use App\Services\Finance\CardPaymentDetails;
use App\Services\Finance\FinanceEntryService;
use App\Services\Finance\PaymentService;
use App\Services\Finance\ReconciliationService;
use App\Services\Finance\RefundService;
use App\Services\Finance\StatementService;
use App\Services\Finance\StudentCredit;
use App\Services\Invoicing\InvoiceMath;
use App\Services\Invoicing\InvoiceService;
use App\Support\BranchContext;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Finans genişletmesi: fatura durumları, KDV hesabı, iade, kısmi/fazla ödeme, fiş dengesi, dönem kilidi,
 * mutabakat ve yetkiler. Bellek içi SQLite (canlı veritabanına ASLA dokunmaz).
 */
class FinanceAccountingTest extends TestCase
{
    private int $cash;
    private int $pos;
    private int $bank;
    private Student $student;
    private int $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        Event::fake([PaymentReceived::class, PaymentVoided::class]);
        $this->schema();
        ChartOfAccounts::forgetCache();
        PeriodLock::forgetCache();
        AccountingPoster::resetState();
        app(BranchContext::class)->set(1);
        $this->seedData();
    }

    // ================================================================== şema

    private function schema(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id(); $t->string('code')->nullable(); $t->string('name'); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->string('name'); $t->string('username')->nullable(); $t->string('user_type')->default('staff');
            $t->string('password')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('student_no'); $t->string('first_name'); $t->string('last_name'); $t->string('full_name')->nullable();
            $t->text('national_id_encrypted')->nullable(); $t->string('national_id_hash')->nullable(); $t->string('national_id_last4')->nullable();
            $t->string('status')->default('active'); $t->string('phone')->nullable(); $t->string('email')->nullable(); $t->string('address')->nullable(); $t->string('photo_path')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('guardians', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('first_name'); $t->string('last_name');
            $t->text('national_id_encrypted')->nullable(); $t->string('national_id_hash')->nullable(); $t->string('national_id_last4')->nullable();
            $t->string('phone')->nullable(); $t->string('whatsapp_phone')->nullable(); $t->string('email')->nullable(); $t->string('address')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('guardian_student', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('guardian_id'); $t->unsignedBigInteger('student_id'); $t->string('relationship')->default('parent');
            $t->boolean('is_primary')->default(false); $t->boolean('is_financially_responsible')->default(false); $t->boolean('receives_notifications')->default(true); $t->timestamps();
        });
        Schema::create('programs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('name'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('academic_terms', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('name'); $t->timestamps();
        });
        Schema::create('education_packages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('name'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('enrollments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('academic_term_id')->nullable();
            $t->unsignedBigInteger('program_id')->nullable(); $t->unsignedBigInteger('education_package_id')->nullable(); $t->unsignedBigInteger('class_group_id')->nullable();
            $t->string('enrollment_no'); $t->decimal('list_price', 14, 2); $t->decimal('discount_amount', 14, 2)->default(0); $t->decimal('scholarship_amount', 14, 2)->default(0);
            $t->decimal('net_price', 14, 2); $t->string('status')->default('active'); $t->date('enrolled_on'); $t->unsignedBigInteger('financial_guardian_id')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('installments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('enrollment_id'); $t->unsignedBigInteger('student_id'); $t->unsignedTinyInteger('sequence');
            $t->date('due_date'); $t->decimal('amount', 14, 2); $t->decimal('paid_amount', 14, 2)->default(0); $t->string('status')->default('pending'); $t->timestamp('paid_at')->nullable(); $t->timestamps();
        });
        Schema::create('finance_accounts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('kind'); $t->string('name'); $t->string('bank_name')->nullable(); $t->string('iban')->nullable();
            $t->string('currency')->default('TRY'); $t->decimal('opening_balance', 14, 2)->default(0); $t->decimal('balance', 14, 2)->default(0); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('finance_categories', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->string('direction'); $t->string('code'); $t->string('name'); $t->boolean('is_system')->default(false); $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('receipt_no')->unique(); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('enrollment_id')->nullable();
            $t->unsignedBigInteger('guardian_id')->nullable(); $t->unsignedBigInteger('finance_account_id'); $t->string('method'); $t->decimal('amount', 14, 2); $t->dateTime('paid_at');
            $t->string('reference')->nullable(); $t->string('note')->nullable(); $t->string('payer_name')->nullable(); $t->unsignedBigInteger('received_by')->nullable();
            $t->timestamp('voided_at')->nullable(); $t->unsignedBigInteger('voided_by')->nullable(); $t->string('void_reason')->nullable(); $t->string('idempotency_key')->nullable()->unique(); $t->timestamps();
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('payment_id'); $t->unsignedBigInteger('installment_id'); $t->decimal('amount', 14, 2); $t->timestamps();
        });
        Schema::create('finance_entries', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('direction'); $t->unsignedBigInteger('finance_category_id'); $t->unsignedBigInteger('finance_account_id');
            $t->decimal('amount', 14, 2); $t->date('entry_date'); $t->string('description'); $t->string('counterparty')->nullable(); $t->string('document_no')->nullable();
            $t->unsignedBigInteger('created_by')->nullable(); $t->timestamp('voided_at')->nullable(); $t->unsignedBigInteger('voided_by')->nullable(); $t->string('void_reason')->nullable(); $t->timestamps();
        });
        Schema::create('account_transfers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->string('kind')->default('transfer'); $t->unsignedBigInteger('from_account_id')->nullable(); $t->unsignedBigInteger('to_account_id');
            $t->decimal('amount', 14, 2); $t->date('transfer_date'); $t->string('description')->nullable(); $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('voided_at')->nullable(); $t->unsignedBigInteger('voided_by')->nullable(); $t->string('void_reason')->nullable(); $t->timestamps();
        });
        Schema::create('account_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('finance_account_id'); $t->decimal('amount', 14, 2); $t->decimal('balance_after', 14, 2);
            $t->string('source_type'); $t->unsignedBigInteger('source_id'); $t->string('description'); $t->dateTime('occurred_at'); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('finance_entry_id')->nullable();
        });
        Schema::create('sequences', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->string('name'); $t->unsignedInteger('year'); $t->unsignedBigInteger('last_value')->default(0);
            $t->unique(['branch_id', 'name', 'year']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('action'); $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable(); $t->string('description'); $t->text('changes')->nullable(); $t->string('ip_address')->nullable(); $t->string('user_agent')->nullable(); $t->timestamp('created_at')->nullable();
        });
        Schema::create('activity_feed', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->string('kind'); $t->string('message'); $t->string('subject_type')->nullable(); $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('student_id')->nullable(); $t->text('meta')->nullable(); $t->timestamp('occurred_at')->nullable();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->string('group'); $t->string('key'); $t->text('value')->nullable(); $t->timestamps();
        });
        Schema::create('message_templates', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->nullable(); $t->string('key'); $t->string('channel')->default('whatsapp'); $t->string('name')->default('x');
            $t->text('body'); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        (require base_path('database/migrations/2026_09_17_800100_create_finance_accounting_tables.php'))->up();
        (require base_path('database/migrations/2026_09_17_800200_create_promissory_notes_table.php'))->up();
    }

    private function seedData(): void
    {
        DB::table('branches')->insert(['id' => 1, 'code' => 'M', 'name' => 'Merkez', 'is_active' => true]);
        $this->cash = FinanceAccount::query()->create(['branch_id' => 1, 'kind' => 'cash', 'name' => 'Kasa'])->id;
        $this->pos = FinanceAccount::query()->create(['branch_id' => 1, 'kind' => 'pos', 'name' => 'POS'])->id;
        $this->bank = FinanceAccount::query()->create(['branch_id' => 1, 'kind' => 'bank', 'name' => 'Banka'])->id;
        FinanceCategory::query()->create(['branch_id' => 1, 'direction' => 'expense', 'code' => 'rent', 'name' => 'Kira']);
        $this->student = Student::query()->create(['branch_id' => 1, 'student_no' => '1001', 'first_name' => 'Ali', 'last_name' => 'Kaya', 'full_name' => 'Ali Kaya']);
        DB::table('programs')->insert(['id' => 1, 'branch_id' => 1, 'name' => 'YKS']);
        $this->enrollment = DB::table('enrollments')->insertGetId(['branch_id' => 1, 'student_id' => $this->student->id, 'program_id' => 1, 'enrollment_no' => 'KYT-1',
            'list_price' => '3000.00', 'net_price' => '3000.00', 'enrolled_on' => '2026-01-01']);
        foreach ([1, 2, 3] as $i) {
            Installment::query()->create(['branch_id' => 1, 'enrollment_id' => $this->enrollment, 'student_id' => $this->student->id, 'sequence' => $i,
                'due_date' => CarbonImmutable::today()->addMonths($i - 2)->toDateString(), 'amount' => '1000.00', 'paid_amount' => '0.00', 'status' => 'pending']);
        }
    }

    private function pay(string $amount, array $extra = []): Payment
    {
        return app(PaymentService::class)->collect($this->student->fresh(), array_merge([
            'finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => $amount,
        ], $extra));
    }

    private function balance(int $accountId): string
    {
        return (string) FinanceAccount::query()->whereKey($accountId)->value('balance');
    }

    private function ledgerOk(): void
    {
        $v = app(LedgerReports::class)->verify(1);
        $this->assertSame([], $v['unbalanced']);
        $this->assertSame([], $v['header_mismatch']);
        foreach ($v['accounts'] as $a) {
            $this->assertTrue($a['ok'], "{$a['name']} hesap bakiyesi ({$a['balance']}) ile muhasebe ({$a['ledger']}) tutmuyor");
        }
    }

    // ================================================================== yuvarlama ve KDV

    public function test_rounding_is_half_up_away_from_zero(): void
    {
        $this->assertSame('1.24', Dec::round('1.235'));
        $this->assertSame('1.23', Dec::round('1.2349'));
        $this->assertSame('-1.24', Dec::round('-1.235'));
        $this->assertSame('0.00', Dec::round('-0.001'));
        $this->assertSame('12.3', Dec::round('12.25', 1));
    }

    public function test_vat_inclusive_line_splits_exactly(): void
    {
        $l = InvoiceMath::line(['quantity' => '1', 'unit_price' => '1000', 'vat_rate' => '10'], true);
        $this->assertSame('909.09', $l['net_amount']);
        $this->assertSame('90.91', $l['vat_amount']);
        $this->assertSame('1000.00', $l['total_amount']);
        $this->assertSame('1000.00', bcadd($l['net_amount'], $l['vat_amount'], 2));
    }

    public function test_vat_exclusive_line_with_discount_and_withholding(): void
    {
        $l = InvoiceMath::line(['quantity' => '3', 'unit_price' => '333.33', 'discount_rate' => '10', 'vat_rate' => '20', 'withholding_tenths' => 5], false);
        $this->assertSame('999.99', $l['gross_amount']);
        $this->assertSame('100.00', $l['discount_amount']);      // 99.999 → 100.00
        $this->assertSame('899.99', $l['net_amount']);
        $this->assertSame('180.00', $l['vat_amount']);           // 179.998 → 180.00
        $this->assertSame('90.00', $l['withholding_amount']);
        $t = InvoiceMath::totals([$l]);
        $this->assertSame('1079.99', $t['grand_total']);
        $this->assertSame('989.99', $t['payable_total']);
    }

    public function test_invalid_invoice_lines_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        InvoiceMath::line(['quantity' => '1', 'unit_price' => '100', 'discount_amount' => '150', 'vat_rate' => '10'], true);
    }

    public function test_card_commission_split_sums_to_gross(): void
    {
        [$c, $n] = CardPaymentDetails::split('1234.56', '1.89');
        $this->assertSame('23.33', $c);
        $this->assertSame('1211.23', $n);
        $this->assertSame('2026-09-21', CardPaymentDetails::expectedDate(CarbonImmutable::parse('2026-09-18 15:00'), 1)->toDateString()); // cuma → pazartesi
    }

    // ================================================================== yevmiye

    public function test_unbalanced_journal_is_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        app(JournalService::class)->post(1, '2026-09-01', 'Hatalı', null, null, 'manual', [
            ['code' => '100', 'debit' => '100'], ['code' => '600', 'credit' => '99.99'],
        ]);
    }

    public function test_journal_model_refuses_unbalanced_totals_and_edits(): void
    {
        $e = app(JournalService::class)->post(1, '2026-09-01', 'Elle', null, null, 'manual', [['code' => '100', 'debit' => '50'], ['code' => '602', 'credit' => '50']]);
        $this->expectException(\LogicException::class);
        $e->forceFill(['total_debit' => '60.00'])->save();
    }

    public function test_payment_posts_balanced_entry_and_void_reverses(): void
    {
        $p = $this->pay('1500');
        $entry = JournalEntry::query()->where('source_type', 'payment')->where('source_id', $p->id)->firstOrFail();
        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->get();
        $this->assertSame('1500.00', (string) $entry->total_debit);
        $this->assertEquals(['100', '340'], $lines->pluck('ledger_code')->all());
        $this->ledgerOk();

        // Aynı kaynak için ikinci çağrı yeni fiş üretmez (idempotent)
        app(AccountingPoster::class)->payment($p);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'payment')->where('source_id', $p->id)->count());

        app(PaymentService::class)->void($p, 'Yanlış öğrenci');
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'payment')->where('source_id', $p->id)->count());
        $this->assertNotNull($entry->fresh()->reversed_at);
        $this->assertSame('0.00', $this->balance($this->cash));
        $tb = app(LedgerReports::class)->trialBalance(1, CarbonImmutable::parse('2020-01-01'), CarbonImmutable::today()->addYear());
        $this->assertTrue($tb['balanced']);
        $this->assertSame('0.00', bcsub($tb['totals']['closing_debit'], '0', 2));
        $this->ledgerOk();
    }

    public function test_entry_transfer_opening_adjustment_are_journaled_and_match_accounts(): void
    {
        $accounts = app(AccountService::class);
        $entries = app(FinanceEntryService::class);
        $opening = $accounts->create(['kind' => 'cash', 'name' => 'Şube kasası', 'opening_balance' => '500']);
        $this->pay('1000');
        $accounts->transfer($this->cash, $this->bank, '400', CarbonImmutable::today()->toDateString(), 'Bankaya');
        $cat = FinanceCategory::query()->where('code', 'rent')->first();
        $e = $entries->record(['direction' => 'expense', 'finance_category_id' => $cat->id, 'finance_account_id' => $this->bank, 'amount' => '250', 'entry_date' => CarbonImmutable::today()->toDateString(), 'description' => 'Eylül kirası']);
        $accounts->adjust(FinanceAccount::query()->find($opening->id), '480', 'Sayım eksiği');
        $entries->void($e, 'Mükerrer kayıt');
        $lines = DB::table('journal_lines')->pluck('ledger_code')->unique()->values()->all();
        foreach (['100', '102', '340', '500', '659', '770'] as $code) {
            $this->assertContains($code, $lines);
        }
        $this->ledgerOk();
    }

    // ================================================================== kısmi / fazla ödeme

    public function test_partial_payment_and_overpayment_modes(): void
    {
        $first = Installment::query()->orderBy('sequence')->first();
        $p = $this->pay('400', ['installment_ids' => [$first->id]]);
        $this->assertContains($first->fresh()->status, ['partial', 'overdue']);
        $this->assertSame('400.00', (string) $first->fresh()->paid_amount);

        try {
            $this->pay('900', ['installment_ids' => [$first->id]]);
            $this->fail('Seçili taksidi aşan tutar reddedilmeliydi.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('fazla olamaz', $e->getMessage());
        }

        // 'next': artan tutar sonraki taksite aktarılır
        $this->pay('900', ['installment_ids' => [$first->id], 'overpayment' => 'next']);
        $rows = Installment::query()->orderBy('sequence')->get();
        $this->assertSame('1000.00', (string) $rows[0]->paid_amount);
        $this->assertSame('300.00', (string) $rows[1]->paid_amount);

        // 'credit': tüm borç kapandıktan sonra artan avans olarak kalır
        $big = $this->pay('2000', ['overpayment' => 'credit']);
        $this->assertSame('300.00', PaymentService::unallocated($big));
        $this->assertSame('300.00', StudentCredit::forStudent($this->student->id));
        $this->assertSame(0, Installment::query()->whereIn('status', ['pending', 'partial', 'overdue'])->count());

        // Avans mahsubu: yeni taksit eklenince uygulanır, para hareketi olmaz
        $new = Installment::query()->create(['branch_id' => 1, 'enrollment_id' => $this->enrollment, 'student_id' => $this->student->id, 'sequence' => 4,
            'due_date' => CarbonImmutable::today()->addMonths(3)->toDateString(), 'amount' => '500.00', 'paid_amount' => '0.00', 'status' => 'pending']);
        $cashBefore = $this->balance($this->cash);
        $this->assertSame('300.00', app(PaymentService::class)->applyCredit($big));
        $this->assertSame('300.00', (string) $new->fresh()->paid_amount);
        $this->assertSame($cashBefore, $this->balance($this->cash));
        $this->assertSame('0.00', StudentCredit::forStudent($this->student->id));
        $this->ledgerOk();
    }

    // ================================================================== iade

    public function test_refund_reopens_installments_and_blocks_payment_void(): void
    {
        $p = $this->pay('2000');
        $refunds = app(RefundService::class);
        $r = $refunds->refund($p, ['amount' => '700', 'finance_account_id' => $this->cash, 'method' => 'cash', 'reason' => 'Kayıt iptali iadesi']);
        $this->assertSame('700.00', (string) $r->from_installments);
        $this->assertSame('1300.00', $this->balance($this->cash));
        // En geç vadeli taksitten geri alınır: 2. taksit 1000 → 300
        $rows = Installment::query()->orderBy('sequence')->get();
        $this->assertSame('1000.00', (string) $rows[0]->paid_amount);
        $this->assertSame('300.00', (string) $rows[1]->paid_amount);
        $this->assertSame('1300.00', RefundService::refundable($p));

        try {
            $refunds->refund($p, ['amount' => '1300.01', 'finance_account_id' => $this->cash, 'method' => 'cash', 'reason' => 'Fazla iade denemesi']);
            $this->fail('İade edilebilir tutarı aşan iade reddedilmeliydi.');
        } catch (BusinessRuleException) {
        }
        try {
            app(PaymentService::class)->void($p, 'İade varken iptal');
            $this->fail('İadesi olan tahsilat iptal edilememeli.');
        } catch (BusinessRuleException) {
        }

        $j = JournalEntry::query()->where('source_type', 'refund')->firstOrFail();
        $codes = DB::table('journal_lines')->where('journal_entry_id', $j->id)->pluck('debit', 'ledger_code');
        $this->assertSame('700.00', bcadd((string) $codes['340'], '0', 2));
        $this->ledgerOk();

        $refunds->void($r, 'Yanlışlıkla girildi');
        $this->assertSame('1000.00', (string) Installment::query()->where('sequence', 2)->value('paid_amount'));
        $this->assertSame('2000.00', $this->balance($this->cash));
        app(PaymentService::class)->void($p->fresh(), 'Artık iptal edilebilir');
        $this->assertSame('0.00', $this->balance($this->cash));
        $this->ledgerOk();
    }

    public function test_refund_from_credit_does_not_touch_installments(): void
    {
        $p = $this->pay('3500', ['overpayment' => 'credit']);
        $r = app(RefundService::class)->refund($p, ['amount' => '500', 'finance_account_id' => $this->cash, 'method' => 'cash', 'reason' => 'Fazla ödeme iadesi']);
        $this->assertSame('500.00', (string) $r->from_credit);
        $this->assertSame('0.00', (string) $r->from_installments);
        $this->assertSame('3000.00', bcadd((string) Installment::query()->sum('paid_amount'), '0', 2));
        $this->assertSame('0.00', StudentCredit::forStudent($this->student->id));
    }

    public function test_cash_refund_cannot_overdraw_cash_account(): void
    {
        $p = $this->pay('1000', ['finance_account_id' => $this->bank, 'method' => 'eft']);
        $this->expectException(BusinessRuleException::class);
        app(RefundService::class)->refund($p, ['amount' => '100', 'finance_account_id' => $this->cash, 'method' => 'cash', 'reason' => 'Kasada para yok']);
    }

    // ================================================================== fatura

    private function draftFor(Payment $p): Invoice
    {
        $ids = app(InvoiceService::class)->draftsFromPayments([$p->id]);
        $this->assertCount(1, $ids);

        return Invoice::query()->findOrFail($ids[0]);
    }

    public function test_invoice_lifecycle_numbering_immutability_and_journal(): void
    {
        $service = app(InvoiceService::class);
        $p = $this->pay('1100');
        $draft = $this->draftFor($p);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->invoice_no);
        $this->assertSame('0.00', $service->availableForInvoice($p));    // taslağa ayrıldı
        $this->assertSame([], $service->draftsFromPayments([$p->id]));   // ikinci taslak yok

        $inv = $service->issue($draft);
        $this->assertSame('issued', $inv->status);
        $this->assertSame('EBE'.date('Y').'000000001', $inv->invoice_no);
        $this->assertSame('not_sent', $inv->integrator_status);
        $this->assertSame($inv->invoice_no, $service->issue($inv)->invoice_no); // çift tıklama

        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.source_type', 'invoice')->get(['l.ledger_code', 'l.debit', 'l.credit'])->keyBy('ledger_code');
        $this->assertSame('1100.00', bcadd((string) $lines['340']->debit, '0', 2));
        $this->assertSame('1000.00', bcadd((string) $lines['600']->credit, '0', 2));
        $this->assertSame('100.00', bcadd((string) $lines['391']->credit, '0', 2));

        try {
            $service->saveDraft(['buyer_type' => 'other', 'buyer_name' => 'X', 'lines' => [['description' => 'Değişiklik', 'quantity' => '1', 'unit_price' => '1', 'vat_rate' => '0']]], $inv);
            $this->fail('Kesilmiş fatura düzenlenememeli.');
        } catch (BusinessRuleException) {
        }
        try {
            $inv->forceFill(['buyer_name' => 'Başkası'])->save();
            $this->fail('Model düzeyinde de değişiklik engellenmeli.');
        } catch (\LogicException) {
        }
        try {
            app(PaymentService::class)->void($p->fresh(), 'Faturalı tahsilat');
            $this->fail('Faturaya bağlı tahsilat iptal edilememeli.');
        } catch (BusinessRuleException) {
        }

        $service->cancel($inv, 'Alıcı bilgisi hatalı');
        $this->assertSame('cancelled', $inv->fresh()->status);
        $this->assertSame('1100.00', $service->availableForInvoice($p->fresh()));  // tekrar faturalanabilir
        try {
            $service->issue($inv->fresh());
            $this->fail('İptal fatura kesilemez.');
        } catch (BusinessRuleException) {
        }
        $this->ledgerOk();
        $tb = app(LedgerReports::class)->trialBalance(1, CarbonImmutable::parse('2020-01-01'), CarbonImmutable::today()->addYear());
        $this->assertTrue($tb['balanced']);
    }

    public function test_return_invoice_cannot_exceed_original_and_later_payment_link(): void
    {
        $service = app(InvoiceService::class);
        $free = $service->saveDraft(['buyer_type' => 'institution', 'buyer_name' => 'Belediye', 'buyer_tax_id' => '1234567890', 'student_id' => $this->student->id,
            'prices_include_vat' => false, 'lines' => [['description' => 'Kurs hizmeti', 'quantity' => '2', 'unit_price' => '500', 'vat_rate' => '20']]]);
        $inv = $service->issue($free);
        $this->assertSame('1200.00', (string) $inv->payable_total);
        // Faturasız kesilen fatura 120'ye düşer
        $this->assertSame('1200.00', bcadd((string) DB::table('journal_lines')->where('ledger_code', '120')->sum('debit'), '0', 2));

        // Sonradan tahsilat bağlama → 340/120 mahsubu
        $p = $this->pay('1200');
        $service->linkPayment($inv, $p);
        $this->assertSame('1200.00', bcadd((string) DB::table('journal_lines')->where('ledger_code', '120')->sum('credit'), '0', 2));

        $ret = $service->createReturnDraft($inv);
        $this->assertSame('return', $ret->kind);
        $service->saveDraft(['kind' => 'return', 'buyer_type' => 'institution', 'buyer_name' => 'Belediye', 'prices_include_vat' => false,
            'lines' => [['description' => 'Kurs hizmeti iadesi', 'quantity' => '1', 'unit_price' => '500', 'vat_rate' => '20']]], $ret);
        $issuedReturn = $service->issue($ret->fresh());
        $this->assertStringStartsWith('EBI', $issuedReturn->invoice_no);

        $second = $service->createReturnDraft($inv);
        $this->expectException(BusinessRuleException::class);   // 500 + 1000 > 1000 matrah
        $service->issue($second);
    }

    public function test_tax_id_validation(): void
    {
        $this->assertTrue(InvoiceService::validTaxId('1234567890'));
        $this->assertTrue(InvoiceService::validTaxId('10000000146'));
        $this->assertFalse(InvoiceService::validTaxId('12345678901'));
        $this->expectException(BusinessRuleException::class);
        app(InvoiceService::class)->saveDraft(['buyer_type' => 'other', 'buyer_name' => 'X', 'buyer_tax_id' => '123',
            'lines' => [['description' => 'Hizmet', 'quantity' => '1', 'unit_price' => '10', 'vat_rate' => '0']]]);
    }

    // ================================================================== dönem kilidi

    public function test_period_lock_blocks_postings_in_closed_month(): void
    {
        $periods = app(PeriodLock::class);
        $last = CarbonImmutable::today()->subMonthNoOverflow();
        try {
            $periods->close(1, CarbonImmutable::today()->format('Y-m'));
            $this->fail('İçinde bulunulan ay kapatılamaz.');
        } catch (BusinessRuleException) {
        }
        $periods->close(1, $last->format('Y-m'));
        $this->assertTrue($periods->isClosed(1, $last->startOfMonth()));

        $before = Payment::query()->count();
        try {
            $this->pay('100', ['paid_at' => $last->startOfMonth()->addDays(3)->toDateTimeString()]);
            $this->fail('Kapalı döneme tahsilat yazılamamalı.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('kapatılmış', $e->getMessage());
        }
        $this->assertSame($before, Payment::query()->count());       // işlem bütünüyle geri alındı
        $this->assertSame('0.00', $this->balance($this->cash));

        // Bugüne kayıt serbest
        $this->pay('100');
        $periods->reopen(1, $last->format('Y-m'), 'Muhasebeci düzeltmesi');
        $this->pay('100', ['paid_at' => $last->startOfMonth()->addDays(3)->toDateTimeString()]);
        $this->ledgerOk();
    }

    // ================================================================== mutabakat

    public function test_pos_settlement_moves_net_to_bank_and_books_commission(): void
    {
        $p1 = $this->pay('1000', ['finance_account_id' => $this->pos, 'method' => 'credit_card']);
        $p2 = $this->pay('500', ['finance_account_id' => $this->pos, 'method' => 'credit_card']);
        $details = PaymentCardDetail::query()->orderBy('id')->get();
        $this->assertCount(2, $details);

        $rec = app(ReconciliationService::class);
        $s = $rec->settle(['pos_account_id' => $this->pos, 'bank_account_id' => $this->bank, 'deposit_date' => CarbonImmutable::today()->toDateString(),
            'actual_net' => '1470.00', 'detail_ids' => $details->pluck('id')->all(), 'idempotency_key' => 'k1']);
        $this->assertSame('30.00', (string) $s->commission_amount);
        $this->assertSame('0.00', $this->balance($this->pos));
        $this->assertSame('1470.00', $this->balance($this->bank));
        $this->assertSame($s->id, $rec->settle(['pos_account_id' => $this->pos, 'bank_account_id' => $this->bank, 'deposit_date' => CarbonImmutable::today()->toDateString(),
            'actual_net' => '1470.00', 'detail_ids' => $details->pluck('id')->all(), 'idempotency_key' => 'k1'])->id);
        $this->assertSame('30.00', bcadd((string) DB::table('journal_lines')->where('ledger_code', '653')->sum('debit'), '0', 2));
        $this->ledgerOk();

        try {
            $rec->settle(['pos_account_id' => $this->pos, 'bank_account_id' => $this->bank, 'deposit_date' => CarbonImmutable::today()->toDateString(),
                'actual_net' => '10', 'detail_ids' => [$details[0]->id]]);
            $this->fail('Eşleşmiş tahsilat ikinci kez eşleşmemeli.');
        } catch (BusinessRuleException) {
        }

        $rec->voidSettlement($s, 'Yanlış yatış');
        $this->assertSame('1500.00', $this->balance($this->pos));
        $this->assertSame('0.00', $this->balance($this->bank));
        $this->assertSame(0, PaymentCardDetail::query()->whereNotNull('pos_settlement_id')->count());
        $this->ledgerOk();

        $marked = $rec->mark(DB::table('account_transactions')->where('finance_account_id', $this->bank)->pluck('id')->all(), CarbonImmutable::today()->toDateString(), 'EKS-1');
        $this->assertSame(2, $marked);
        $this->assertSame(2, $rec->unmark(DB::table('account_transactions')->where('finance_account_id', $this->bank)->pluck('id')->all()));
    }

    // ================================================================== ekstre

    public function test_statement_balance_equals_open_debt_minus_credit(): void
    {
        $this->pay('1500');
        $p = $this->pay('2000', ['overpayment' => 'credit']);
        app(RefundService::class)->refund($p, ['amount' => '200', 'finance_account_id' => $this->cash, 'method' => 'cash', 'reason' => 'Kısmi iade']);
        $s = app(StatementService::class)->build([$this->student->id]);
        $open = (string) Installment::query()->whereIn('status', ['pending', 'partial', 'overdue'])->selectRaw('SUM(amount - paid_amount) AS r')->value('r');
        $expected = bcsub(bcadd($open ?: '0', '0', 2), StudentCredit::forStudent($this->student->id), 2);
        $this->assertSame($expected, $s['totals']['balance']);
    }

    // ================================================================== senet

    public function test_promissory_notes_are_numbered_reused_and_reissued_when_amount_changes(): void
    {
        $svc = app(\App\Services\Finance\PromissoryNoteService::class);
        $installments = $svc->query(['enrollment_ids' => [$this->enrollment]])->get();
        $this->assertCount(3, $installments);
        $preview = $svc->preview($installments);
        $this->assertSame('3000.00', $preview['total']);
        $this->assertSame(['TCKN', 'adres'], $preview['rows'][0]['missing']);   // borçlu öğrenci, bilgiler eksik

        $notes = $svc->prepare($installments);
        $this->assertCount(3, $notes);
        $this->assertSame('SNT-'.date('Y').'-000001', $notes[0]->note_no);
        $again = $svc->prepare($svc->query(['enrollment_ids' => [$this->enrollment]])->get());
        $this->assertEquals($notes->pluck('id')->all(), $again->pluck('id')->all());   // tekrar hazırlama aynı senetler

        $response = $svc->pdf($notes, 3, true);
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(1, (int) \App\Models\PromissoryNote::query()->find($notes[0]->id)->print_count);

        // Basılmış senedin taksit tutarı değişirse eski senet iptal, yeni numara
        Installment::query()->whereKey($notes[0]->installment_id)->update(['amount' => '1200.00']);
        $fresh = $svc->prepare($svc->query(['installment_ids' => [$notes[0]->installment_id]])->get());
        $this->assertNotSame($notes[0]->id, $fresh[0]->id);
        $this->assertNotNull(\App\Models\PromissoryNote::query()->find($notes[0]->id)->voided_at);
        $this->assertSame('1200.00', (string) $fresh[0]->amount);

        // Ödenen taksit varsayılan filtrede basılmaz
        $this->pay('1000', ['installment_ids' => [$notes[1]->installment_id]]);
        $this->assertCount(2, $svc->query(['enrollment_ids' => [$this->enrollment]])->get());
        $this->assertCount(3, $svc->query(['enrollment_ids' => [$this->enrollment], 'include_paid' => true])->get());
    }

    public function test_receipt_and_voucher_documents_render(): void
    {
        $p = $this->pay('750');
        $docs = app(\App\Services\Finance\FinanceExtraDocuments::class);
        $this->assertStringStartsWith('%PDF', $docs->receipts(collect([$p, $this->pay('10')]))->getContent());
        $this->assertStringStartsWith('%PDF', $docs->voucher('payment', $p->id)->getContent());
        $this->assertSame('HESABA GİREN', $docs->voucherData('payment', $p->id)['direction_label']);
        $this->expectException(BusinessRuleException::class);
        $docs->voucherData('bilinmeyen', 1);
    }

    // ================================================================== yetkiler

    public function test_permissions_and_roles(): void
    {
        $all = Permissions::all();
        foreach (['finance.invoice', 'finance.accounting', 'finance.period_close', 'finance.reconcile', 'finance.refund', 'finance.collections'] as $key) {
            $this->assertContains($key, $all);
            $this->assertContains($key, Permissions::defaultRoles()['muhasebe']['permissions']);
            $this->assertContains($key, Permissions::defaultRoles()['mudur']['permissions']);
            $this->assertContains($key, Permissions::defaultRoles()['yonetici']['permissions']);
            $this->assertNotContains($key, Permissions::defaultRoles()['danisman']['permissions']);
        }
        $this->assertContains('finance.view', Permissions::defaultRoles()['danisman']['permissions']);

        $expect = [
            'POST api/v1/finance/invoices/{invoice}/issue' => 'permission:finance.invoice',
            'POST api/v1/finance/invoices/{invoice}/cancel' => 'permission:finance.invoice',
            'POST api/v1/finance/payments/{payment}/refunds' => 'permission:finance.refund',
            'POST api/v1/finance/reconciliation/pos-settlements' => 'permission:finance.reconcile',
            'POST api/v1/finance/accounting/periods/close' => 'permission:finance.period_close',
            'GET api/v1/finance/accounting/trial-balance' => 'permission:finance.accounting',
            'POST api/v1/finance/collections/notes' => 'permission:finance.collections',
            'GET api/v1/finance/invoices' => 'permission:finance.view',
            'PUT api/v1/finance/settings' => 'permission:settings.manage',
        ];
        foreach ($expect as $key => $permission) {
            [$method, $uri] = explode(' ', $key, 2);
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));
            $this->assertNotNull($route, "Rota yok: {$key}");
            $this->assertContains($permission, $route->gatherMiddleware(), "{$key} {$permission} istemeli");
            $this->assertContains('staff', $route->gatherMiddleware(), "{$key} personel ara katmanında olmalı");
        }
    }
}
