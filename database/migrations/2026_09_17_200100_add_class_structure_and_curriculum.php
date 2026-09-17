<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Özelleştirilebilir sınıf yapısı + sınıfa özel müfredat + program botunda öğretmen hedef saati.
 * Yalnız ekleme yapar; mevcut veriye dokunmaz. Yapının kendisi ayarlarda (`classes.structure`) tutulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->string('track', 10)->nullable()->after('section');        // SAY | EA | SOZ | DIL | TYT | LGS
            $table->string('short_name', 20)->nullable()->after('track');     // "12A", "12 SAY"
            $table->string('color', 20)->nullable()->after('short_name');     // UI renk anahtarı
        });

        // Sınıf başına ders-saat geçersiz kılma. Satır yoksa program_subject.weekly_hours kullanılır.
        // weekly_hours = 0 → sınıf bu dersi almaz.
        Schema::create('class_group_subject_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekly_hours')->default(0);
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete(); // sabit öğretmen
            $table->unsignedTinyInteger('max_per_day')->nullable();                   // günlük üst sınır (sert)
            $table->unsignedTinyInteger('block_size')->nullable();                    // 2 = ikişer saatlik blok (tercih)
            $table->timestamps();
            $table->unique(['class_group_id', 'subject_id']);
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->unsignedSmallInteger('target_weekly_hours')->nullable()->after('max_weekly_hours'); // bot: hedefe yakın yük
            $table->boolean('in_timetable')->default(true)->after('target_weekly_hours');              // bot atama yapabilir mi
        });
    }

    public function down(): void
    {
        Schema::table('teachers', fn (Blueprint $table) => $table->dropColumn(['target_weekly_hours', 'in_timetable']));
        Schema::dropIfExists('class_group_subject_hours');
        Schema::table('class_groups', fn (Blueprint $table) => $table->dropColumn(['track', 'short_name', 'color']));
    }
};
