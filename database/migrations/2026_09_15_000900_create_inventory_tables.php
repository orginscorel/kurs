<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 200);
            $table->string('publisher', 120)->nullable();
            $table->string('barcode', 40)->nullable();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->integer('stock')->default(0);            // önbellek; kaynak stock_movements
            $table->integer('min_stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'barcode']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');                     // + giriş / − çıkış
            $table->string('kind', 20);                      // purchase | delivery | return | adjustment
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at']);
            $table->index('student_id');
        });

        // İçe aktarma sihirbazı işleri (öğrenci, veli, öğretmen, ödeme, sınav sonucu)
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('entity', 30);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->json('mapping')->nullable();
            $table->string('status', 20)->default('uploaded'); // uploaded | mapped | processing | completed | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);                      // daily | weekly | manual
            $table->string('status', 12);                    // running | success | failed
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['backup_runs', 'import_jobs', 'stock_movements', 'products'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
