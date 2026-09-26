<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kütüphane/etüt kayıtlarının programı olmayabilir (sınıf/program gerektirmez). enrollments.program_id
 * NULL kabul edecek şekilde gevşetilir. FK korunur (MariaDB modify nullability'yi FK'yı bozmadan yapar).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('enrollments', 'program_id')) {
            DB::statement('ALTER TABLE enrollments MODIFY program_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // Geri alınırken NULL program'lı kayıt varsa kısıtlanamaz; güvenli tarafta bırakılır (no-op).
    }
};
