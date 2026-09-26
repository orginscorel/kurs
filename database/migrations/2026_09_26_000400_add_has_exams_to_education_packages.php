<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pakete "deneme sistemi dahil" bayrağı (koçluk gibi). Bu paketi alan öğrenci deneme öğrencileri
 * listesine girer; ekstra deneme paketi de has_exams=true bir pakettir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('education_packages', 'has_exams')) {
            Schema::table('education_packages', function (Blueprint $table) {
                $table->boolean('has_exams')->default(false)->after('has_coaching');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('education_packages', 'has_exams')) {
            Schema::table('education_packages', function (Blueprint $table) {
                $table->dropColumn('has_exams');
            });
        }
    }
};
