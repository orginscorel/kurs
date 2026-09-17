<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Optik okuma kolon düzeni şablonları: aynı optik okuyucudan gelen dosyalar
         * için kolon eşleşmesi (CSV/XLSX) ya da sabit genişlikli düzen (TXT) bir kez
         * tanımlanır, sonraki içe aktarmalarda tek tıkla yeniden kullanılır.
         */
        Schema::create('optical_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->string('format', 10);                    // csv | xlsx | txt | json
            $table->json('mapping');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'format']);
        });

        // İçe aktarma ilerlemesi (kuyruk işi) ve satır bazlı hata raporu
        Schema::table('optical_imports', function (Blueprint $table) {
            $table->unsignedInteger('processed_rows')->default(0)->after('matched_rows');
            $table->json('report')->nullable()->after('unmatched');   // {errors:[{row,student_no,message}], booklets:{A:n,B:n}}
            $table->timestamp('started_at')->nullable()->after('error');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('optical_imports', function (Blueprint $table) {
            $table->dropColumn(['processed_rows', 'report', 'started_at', 'finished_at']);
        });
        Schema::dropIfExists('optical_layouts');
    }
};
