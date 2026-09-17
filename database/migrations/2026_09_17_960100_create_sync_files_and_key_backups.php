<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eşitleme eksikleri (yalnız ekleme). Ayrıntı: docs/SYNC.md
 *
 *  - sync_files: içerik adresli (sha256) dosya dizini. Sunucuda eşitlenen satırların başvurduğu dosyalar
 *    (öğrenci fotoğrafı, belgeler, ödev dosyaları/teslimleri, disiplin ekleri, logolar); yerel düğümde
 *    aynı tablo indirme/yükleme kuyruğudur (durum, deneme sayısı, sonraki deneme).
 *  - sync_key_backups: kurs:data-key migrate öncesi eski (APP_KEY ile) şifreli değerler → geri dönüş yolu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_files')) {
            Schema::create('sync_files', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id')->nullable()->index();
                $t->string('disk', 20);
                $t->string('path', 255);
                $t->char('sha256', 64)->nullable()->index();
                $t->unsignedBigInteger('size')->default(0);
                $t->string('mime', 120)->nullable();
                $t->string('owner_table', 64);                      // students | teachers | users | branches | settings | documents
                $t->string('owner_uuid', 36)->nullable();
                $t->string('owner_column', 64);                     // photo_path | avatar_path | logo_path | path
                $t->string('owner_kind', 40)->nullable();           // belgenin sahibi (documentable_type): yetki süzgeci
                $t->string('status', 16)->default('present');      // sunucu: present | gone · yerel: present | download | upload | failed | gone
                $t->unsignedInteger('attempts')->default(0);
                $t->string('last_error', 300)->nullable();
                $t->timestamp('next_attempt_at')->nullable();
                $t->unsignedInteger('file_mtime')->nullable();      // dizin tazeliği (yeniden özet alma gerekmesin)
                $t->timestamps(3);
                $t->unique(['disk', 'path']);
                $t->index(['status', 'next_attempt_at']);
                $t->index('updated_at');
            });
        }

        if (! Schema::hasTable('sync_key_backups')) {
            Schema::create('sync_key_backups', function (Blueprint $t) {
                $t->id();
                $t->string('batch', 40)->index();
                $t->string('table_name', 64);
                $t->unsignedBigInteger('row_id');
                $t->string('column_name', 64);
                $t->longText('old_value')->nullable();              // eski şifreli değer (APP_KEY) — düz metin DEĞİL
                $t->char('old_hash', 64)->nullable();               // eski TC özeti (varsa)
                $t->char('new_digest', 64);                         // yeni şifreli değerin sha256'sı (sonradan değişti mi?)
                $t->timestamp('migrated_at')->nullable();
                $t->timestamp('restored_at')->nullable();
                $t->index(['table_name', 'row_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_key_backups');
        Schema::dropIfExists('sync_files');
    }
};
