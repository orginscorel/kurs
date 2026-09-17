<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /*
     * Finans genişletmesi (YALNIZ EKLEME; mevcut tablolara dokunulmaz):
     * - Muhasebeleşme: hesap planı (TDHP), eşleme, yevmiye fişi + satırları, dönem kilidi.
     *   Fiş başlığında borç = alacak DB CHECK kısıtıyla da korunur (MariaDB).
     * - Fatura / e-Arşiv taslağı: fatura, kalem, tahsilat bağlantısı. Entegratör bağlı değil.
     * - Tahsilat: iade (+ taksit etkisi), kart/POS komisyon ayrıntısı, POS yatışı (mutabakat),
     *   banka hareketi eşleştirme işareti, ödeme sözü / takip notu.
     * - Yeni yetkiler ve rol atamaları.
     */
    private const PERMISSIONS = ['finance.invoice', 'finance.accounting', 'finance.period_close', 'finance.reconcile', 'finance.refund', 'finance.collections'];

    private const ROLE_GRANTS = [
        'yonetici' => self::PERMISSIONS,
        'mudur' => self::PERMISSIONS,
        'muhasebe' => self::PERMISSIONS,
    ];

    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 160);
            $table->string('type', 12);              // asset | liability | equity | income | expense
            $table->string('normal_side', 6);        // debit | credit
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        // Kaynak → hesap kodu eşlemesi (kasa/banka hesabı, gelir/gider kategorisi, özel roller)
        Schema::create('ledger_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('source_type', 30);       // finance_account | finance_category | role
            $table->string('source_key', 60);
            $table->string('ledger_code', 20);
            $table->timestamps();
            $table->unique(['branch_id', 'source_type', 'source_key']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('entry_no', 30)->unique();
            $table->date('entry_date');
            $table->string('description', 300);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_event', 40);       // payment | payment_void | entry | ... | manual
            $table->decimal('total_debit', 16, 2);
            $table->decimal('total_credit', 16, 2);
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // İdempotentlik: aynı kaynak olayı için ikinci fiş oluşmaz
            $table->unique(['branch_id', 'source_type', 'source_id', 'source_event'], 'journal_source_unique');
            $table->index(['branch_id', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->date('entry_date');
            $table->string('ledger_code', 20);
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->string('description', 300)->nullable();
            $table->string('partner_type', 20)->nullable();   // student | guardian | other (muavin)
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('partner_name', 160)->nullable();
            $table->foreignId('finance_account_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['branch_id', 'ledger_code', 'entry_date']);
            $table->index(['partner_type', 'partner_id']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->char('period', 7);                        // YYYY-MM
            $table->string('status', 10)->default('closed');  // closed | open (yeniden açıldı)
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'period']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('invoice_no', 30)->nullable()->unique();  // kesilince atanır
            $table->string('kind', 10)->default('sales');             // sales | return
            $table->string('document_type', 12)->default('e_archive'); // e_archive | e_invoice | paper
            $table->string('status', 10)->default('draft');           // draft | issued | cancelled
            $table->date('issue_date');
            $table->string('buyer_type', 12);                         // guardian | student | institution | other
            $table->unsignedBigInteger('buyer_id')->nullable();
            $table->string('buyer_name', 200);
            $table->text('buyer_tax_id')->nullable();                 // şifreli (TCKN/VKN)
            $table->string('buyer_tax_id_last4', 4)->nullable();
            $table->string('buyer_tax_office', 120)->nullable();
            $table->string('buyer_address', 400)->nullable();
            $table->string('buyer_email', 160)->nullable();
            $table->string('buyer_phone', 30)->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->boolean('prices_include_vat')->default(true);
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('withholding_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);        // KDV dahil toplam
            $table->decimal('payable_total', 14, 2)->default(0);      // tevkifat düşülmüş ödenecek
            $table->string('notes', 1000)->nullable();
            $table->string('integrator_status', 20)->default('not_sent');
            $table->string('ettn', 36)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 300)->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'issue_date']);
            $table->index(['student_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('description', 300);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 12)->default('ADET');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->unsignedTinyInteger('withholding_tenths')->default(0);  // tevkifat oranı: n/10
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('withholding_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['invoice_id', 'payment_id']);
            $table->index('payment_id');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('refund_no', 30)->unique();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('finance_account_id')->constrained();
            $table->string('method', 20);
            $table->decimal('amount', 14, 2);
            $table->decimal('from_credit', 14, 2)->default(0);        // dağıtılmamış (fazla ödeme) kısmından
            $table->decimal('from_installments', 14, 2)->default(0);  // taksitlerden (borç yeniden açılır)
            $table->decimal('invoiced_portion', 14, 2)->default(0);   // faturalı kısım (120), kalanı faturasız avans (340)
            $table->dateTime('refunded_at');
            $table->string('reason', 300);
            $table->string('payee_name', 160)->nullable();
            $table->string('reference', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->timestamps();
            $table->index(['branch_id', 'refunded_at']);
            $table->index('payment_id');
        });

        Schema::create('refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::create('pos_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('pos_account_id')->constrained('finance_accounts');
            $table->foreignId('bank_account_id')->constrained('finance_accounts');
            $table->date('deposit_date');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('expected_net', 14, 2)->default(0);
            $table->string('reference', 120)->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('account_transfer_id')->nullable()->constrained('account_transfers')->nullOnDelete();
            $table->foreignId('finance_entry_id')->nullable()->constrained('finance_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->timestamps();
        });

        // Kart / POS tahsilatının komisyon, net tutar ve beklenen yatış tarihi (tahsilat satırı değişmez)
        Schema::create('payment_card_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('payment_id')->unique()->constrained();
            $table->decimal('commission_rate', 6, 3)->default(0);    // yüzde
            $table->decimal('commission_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->date('expected_deposit_date');
            $table->unsignedTinyInteger('card_installments')->default(1);
            $table->foreignId('pos_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'pos_settlement_id', 'expected_deposit_date'], 'card_details_pending_idx');
        });

        // Banka hareketinin ekstreyle eşleştirildiği işareti (hareket satırı değişmez)
        Schema::create('reconciliation_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('account_transaction_id')->unique()->constrained();
            $table->date('statement_date');
            $table->string('statement_ref', 120)->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Tahsilat takibi: ödeme sözü, görüşme notu, hatırlatma taslağı (GÖNDERİLMEZ)
        Schema::create('collection_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12);                        // promise | note | reminder
            $table->date('promised_date')->nullable();
            $table->decimal('promised_amount', 14, 2)->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('open');     // open | kept | broken | done
            $table->text('body')->nullable();
            $table->string('channel', 20)->nullable();         // reminder taslağı için kanal önerisi
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'kind', 'status', 'promised_date']);
            $table->index(['student_id', 'created_at']);
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_balanced CHECK (total_debit = total_credit AND total_debit > 0)');
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit > 0 OR credit > 0))');
        }

        $this->grantPermissions();
    }

    private function grantPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }
        $now = now();
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (self::ROLE_GRANTS as $role => $permissions) {
            $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('id') as $pid) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $pid, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['collection_notes', 'reconciliation_marks', 'payment_card_details', 'pos_settlements', 'refund_allocations', 'refunds',
            'invoice_payments', 'invoice_lines', 'invoices', 'accounting_periods', 'journal_lines', 'journal_entries', 'ledger_mappings', 'ledger_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
