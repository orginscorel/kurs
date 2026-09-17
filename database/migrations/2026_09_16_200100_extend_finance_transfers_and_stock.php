<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Finans modülü genişletmesi (geriye uyumlu, yalnız ekleme):
     * - account_transfers: "kind" (transfer | opening | adjustment). Açılış bakiyesi ve kasa sayım
     *   farkı da bir belgeye bağlı hesap hareketi olur; bakiye yine yalnızca Ledger::post ile değişir.
     *   Açılış/düzeltme kaydında karşı hesap yoktur → from_account_id NULL olabilir.
     * - account_transfers: iptal eden kişi ve gerekçe.
     * - stock_movements: öğrenciye satışta oluşturulan gelir kaydı bağlantısı.
     */
    public function up(): void
    {
        Schema::table('account_transfers', function (Blueprint $table) {
            $table->string('kind', 12)->default('transfer')->after('branch_id');
            $table->unsignedBigInteger('from_account_id')->nullable()->change();
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 300)->nullable()->after('voided_by');
            $table->index(['branch_id', 'kind', 'transfer_date']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('finance_entry_id')->nullable()->after('unit_price')->constrained('finance_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_entry_id');
        });

        Schema::table('account_transfers', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'kind', 'transfer_date']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['kind', 'void_reason']);
        });
    }
};
