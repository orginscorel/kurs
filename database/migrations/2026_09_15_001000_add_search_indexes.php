<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Global arama için FULLTEXT indeksler (MariaDB InnoDB). 100.000+ öğrencide de
 * "ahm yıl" gibi kelime öneki aramaları tam tarama yapmadan döner.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Yerel kurulum (SQLite) FULLTEXT desteklemez; arama orada LIKE/uyumluluk katmanıyla yapılır.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement('ALTER TABLE students ADD FULLTEXT INDEX students_fulltext (full_name, school_name)');
        DB::statement('ALTER TABLE guardians ADD FULLTEXT INDEX guardians_fulltext (first_name, last_name)');
        DB::statement('ALTER TABLE teachers ADD FULLTEXT INDEX teachers_fulltext (first_name, last_name, specialty)');
        DB::statement('ALTER TABLE leads ADD FULLTEXT INDEX leads_fulltext (first_name, last_name, guardian_name)');
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement('ALTER TABLE students DROP INDEX students_fulltext');
        DB::statement('ALTER TABLE guardians DROP INDEX guardians_fulltext');
        DB::statement('ALTER TABLE teachers DROP INDEX teachers_fulltext');
        DB::statement('ALTER TABLE leads DROP INDEX leads_fulltext');
    }
};
