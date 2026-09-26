<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paket türü: Ders (course) / Kütüphane (library) / Etüt (study).
 * Kütüphane ve etüt paketleri bir programa/sınıfa bağlı olmak zorunda değildir; hem aktif öğrenciye
 * hem mezuna atanabilir. Mevcut tüm paketler geriye dönük "course" sayılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('education_packages', 'type')) {
            Schema::table('education_packages', function (Blueprint $table) {
                $table->string('type', 12)->default('course')->after('program_id'); // course | library | study
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('education_packages', 'type')) {
            Schema::table('education_packages', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
