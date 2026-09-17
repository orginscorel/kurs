<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Senet (bono) kaydı: her taksit için bir senet. Basım sayısı tutulur (tekrar basımda "SURETİDİR" ibaresi).
     * Taksit tutarı basılmış senetten farklılaşırsa eski senet iptal edilip yeni numara verilir.
     * Durum (ödendi/iptal) taksitten okunur. Yalnız ekleme.
     */
    public function up(): void
    {
        Schema::create('promissory_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('note_no', 30)->unique();
            $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('due_date');
            $table->date('issue_date');
            $table->string('issue_place', 120);
            $table->string('payment_place', 120);
            $table->string('payee_name', 200);
            $table->string('debtor_name', 200);
            $table->text('debtor_tax_id')->nullable();              // şifreli
            $table->string('debtor_address', 400)->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('first_printed_at')->nullable();
            $table->timestamp('last_printed_at')->nullable();
            $table->foreignId('last_printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['installment_id', 'voided_at']);
            $table->index(['branch_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promissory_notes');
    }
};
