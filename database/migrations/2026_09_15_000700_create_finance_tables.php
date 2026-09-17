<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Finans kuralları:
     * - Tutarlar decimal(14,2). Kayan nokta yok.
     * - Tahsilat ve hesap hareketleri DEĞİŞTİRİLMEZ. Hata düzeltmesi "iptal"
     *   (ters kayıt) ile yapılır; iptal eden kişi, zaman ve gerekçe saklanır.
     * - Hesap bakiyesi hareket tablosundan türetilir; balance sütunu yalnızca
     *   aynı transaction içinde güncellenen önbellektir.
     */
    public function up(): void
    {
        Schema::create('education_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->decimal('list_price', 14, 2);
            $table->unsignedTinyInteger('default_installments')->default(1);
            $table->text('includes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Kayıt: öğrencinin bir dönem/programa kaydı ve ücret sözleşmesi
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained();
            $table->foreignId('program_id')->constrained();
            $table->foreignId('education_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('enrollment_no', 30)->unique();
            $table->decimal('list_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->string('discount_reason', 200)->nullable();
            $table->decimal('scholarship_amount', 14, 2)->default(0);
            $table->string('scholarship_reason', 200)->nullable();
            $table->decimal('net_price', 14, 2);
            $table->string('status', 20)->default('active'); // pending | active | frozen | withdrawn | completed
            $table->date('enrolled_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('financial_guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('contract_no', 30)->unique();
            $table->longText('body_snapshot');               // imza anındaki metin, sonradan değişmez
            $table->string('pdf_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_by_name', 160)->nullable();
            $table->timestamps();
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            // pending | partial | paid | overdue | cancelled — overdue gece işiyle işaretlenir
            $table->string('status', 12)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'sequence']);
            $table->index(['branch_id', 'status', 'due_date']);
            $table->index(['student_id', 'due_date']);
        });

        // Kasa / banka hesabı / POS
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('kind', 10);                      // cash | bank | pos
            $table->string('name', 120);
            $table->string('bank_name', 120)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('currency', 3)->default('TRY');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('direction', 10);                 // income | expense
            $table->string('code', 40);
            $table->string('name', 120);
            $table->boolean('is_system')->default(false);    // öğrenci ödemeleri gibi silinemeyenler
            $table->timestamps();
            $table->unique(['branch_id', 'direction', 'code']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('receipt_no', 30)->unique();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_account_id')->constrained();
            // cash | credit_card | bank_transfer | eft | pos | online | cheque | other
            $table->string('method', 20);
            $table->decimal('amount', 14, 2);
            $table->dateTime('paid_at');
            $table->string('reference', 120)->nullable();    // dekont / POS provizyon no
            $table->string('note', 500)->nullable();
            $table->string('payer_name', 160)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique(); // çift tıklamada çift tahsilat yok
            $table->timestamps();
            $table->index(['branch_id', 'paid_at']);
            $table->index(['student_id', 'paid_at']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->index('installment_id');
        });

        // Diğer gelir ve giderler (öğrenci tahsilatı dışında)
        Schema::create('finance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('direction', 10);                 // income | expense
            $table->foreignId('finance_category_id')->constrained();
            $table->foreignId('finance_account_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->date('entry_date');
            $table->string('description', 500);
            $table->string('counterparty', 160)->nullable();
            $table->string('document_no', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'direction', 'entry_date']);
        });

        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('from_account_id')->constrained('finance_accounts');
            $table->foreignId('to_account_id')->constrained('finance_accounts');
            $table->decimal('amount', 14, 2);
            $table->date('transfer_date');
            $table->string('description', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });

        // Hesap defteri: her para hareketi tek satır, işaretli tutar (+ giriş / − çıkış)
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('finance_account_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->morphs('source');                        // Payment | FinanceEntry | AccountTransfer
            $table->string('description', 300);
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['finance_account_id', 'occurred_at']);
        });

        // Ödeme hatırlatma günlüğü: aynı taksit için aynı kural bir kez çalışır
        Schema::create('installment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
            $table->string('rule_key', 30);                  // before_5 | before_2 | due | after_3 | after_7
            $table->foreignId('outbound_message_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['installment_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        foreach (['installment_reminders', 'account_transactions', 'account_transfers', 'finance_entries', 'payment_allocations', 'payments', 'finance_categories', 'finance_accounts', 'installments', 'contracts', 'enrollments', 'education_packages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
