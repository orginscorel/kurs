<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ders programı sistemi: zaman şablonları, program botu önerileri, tatiller,
 * iCal akışları + mevcut akademik tablolara kilit / zorluk / telafi / tatil alanları.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Zaman şablonu: gün × ders saati dilimleri. days = {"1": [["16:30","17:10"], …], "6": […]}
        Schema::create('time_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->json('days');
            $table->json('generator')->nullable();   // hızlı oluşturucu ayarları (başlangıç, süre, teneffüs, öğle arası)
            $table->json('levels')->nullable();      // [9,10] → bu seviyedeki sınıflara "seviyeye göre ata" ile bağlanır
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('class_group_time_template', function (Blueprint $table) {
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_template_id')->constrained()->cascadeOnDelete();
            $table->primary(['class_group_id', 'time_template_id']);
        });

        // Program botu çalıştırması = öneri. Uygulanınca önceki şablonların anlık görüntüsü saklanır (geri al).
        Schema::create('timetable_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('academic_term_id')->constrained();
            $table->json('class_group_ids');
            $table->json('settings');                 // ağırlıklar, günlük üst sınır, süre sınırı, tohum
            $table->string('status', 20)->default('queued'); // queued | running | completed | failed | applied | discarded | rolled_back
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('log')->nullable();
            $table->longText('result')->nullable();   // JSON: yerleşimler, yerleşemeyenler, puan kırılımı, öğretmen yükü
            $table->decimal('penalty', 12, 2)->nullable();
            $table->unsignedTinyInteger('quality')->nullable();
            $table->unsignedSmallInteger('required_count')->default(0);
            $table->unsignedSmallInteger('placed_count')->default(0);
            $table->unsignedSmallInteger('unplaced_count')->default(0);
            $table->unsignedSmallInteger('hard_violations')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->date('apply_from')->nullable();
            $table->longText('snapshot')->nullable(); // JSON: uygulama öncesi şablonlar + yeni şablon kimlikleri
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'created_at']);
        });

        // Resmi / kurum tatili. Eklenince aralıktaki oturumlar gerekçeyle iptal edilir.
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 120);
            $table->string('kind', 20)->default('official');   // official | institution
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('cancel_sessions')->default(true);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'starts_on']);
        });

        // iCal akışı: rastgele gizli jeton (yalnız SHA-256 özeti saklanır). Yenilenince eskisi geçersiz olur.
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('owner_type', 20);          // teacher | student | class_group | classroom
            $table->unsignedBigInteger('owner_id');
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::table('lesson_schedules', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('valid_until'); // program botu dokunmaz
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->boolean('is_hard')->default(false)->after('color');         // bot: günün erken saatlerine
        });

        Schema::table('lesson_sessions', function (Blueprint $table) {
            $table->foreignId('makeup_of_id')->nullable()->after('lesson_schedule_id')->constrained('lesson_sessions')->nullOnDelete();
            $table->foreignId('holiday_id')->nullable()->after('makeup_of_id')->constrained('holidays')->nullOnDelete();
        });

        // Makul varsayılan: sayısal dersler "zor" (ders ayarından değiştirilebilir)
        DB::table('subjects')->whereIn('code', ['MAT', 'GEO', 'FIZ', 'KIM'])->update(['is_hard' => true]);
    }

    public function down(): void
    {
        Schema::table('lesson_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holiday_id');
            $table->dropConstrainedForeignId('makeup_of_id');
        });
        Schema::table('subjects', fn (Blueprint $table) => $table->dropColumn('is_hard'));
        Schema::table('lesson_schedules', fn (Blueprint $table) => $table->dropColumn('is_locked'));
        foreach (['calendar_feeds', 'holidays', 'timetable_runs', 'class_group_time_template', 'time_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
