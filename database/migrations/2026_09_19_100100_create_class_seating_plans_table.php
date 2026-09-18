<?php

use App\Sync\ChangeRecorder;
use App\Sync\Sweeper;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SINIF OTURMA PLANI — oda düzeninden (classroom_layouts) AYRI. Bir derslik farklı saatlerde birden çok sınıfça
 * kullanılır; her (sınıf + oda düzeni) çifti kendi oturma planını taşır. Yalnız EKLEME yapar.
 *
 *  seats     JSON {masa_id: [öğrenci_uuid|null, …]} — masa oda düzenindeki nesne kimliği; öğrenci UUID (düğümler arası sabit)
 *  statuses  JSON {masa_id: 'reserved'|'unavailable'} — bu sınıf için masa durumu
 * Oda düzeninden silinen masadaki öğrenci plan okunurken "yerleştirilmemiş" sayılır (kayıt silinmez).
 * Eşitleme: REFERENCE (iki yönlü, uuid) — app/Sync/SyncRegistry.php. SQLite ve MySQL uyumlu, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('class_seating_plans')) {
            Schema::create('class_seating_plans', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->nullable()->unique();
                $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $t->foreignId('class_group_id')->constrained('class_groups')->cascadeOnDelete();
                $t->foreignId('classroom_layout_id')->constrained('classroom_layouts')->cascadeOnDelete();
                $t->unsignedInteger('layout_version')->default(0);   // planın kaydedildiği andaki oda düzeni sürümü
                $t->longText('seats');
                $t->text('statuses')->nullable();
                $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['class_group_id', 'classroom_layout_id']);
            });
        }

        if (Schema::hasTable('sync_changes') && Schema::hasTable('sync_table_state')) {
            SyncRegistry::flush();
            app(SyncSchema::class)->flush();
            app(ChangeRecorder::class)->reset();
            app(Sweeper::class)->baseline(['class_seating_plans'], true);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_seating_plans');
        if (Schema::hasTable('sync_table_state')) {
            \Illuminate\Support\Facades\DB::table('sync_table_state')->where('table_name', 'class_seating_plans')->delete();
        }
        if (Schema::hasTable('sync_row_hashes')) {
            \Illuminate\Support\Facades\DB::table('sync_row_hashes')->where('table_name', 'class_seating_plans')->delete();
        }
    }
};
