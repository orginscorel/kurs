<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masaüstü (yerel kurulum) oturumları — yalnız ekleme, geri alınabilir.
 *
 *  sync_device_sessions     Cihazın raporladığı AÇIK yerel oturumlar (ham oturum kimliği YOK; yalnız sha256 özeti).
 *                           Cihaz her raporda tam listeyi gönderir; raporda olmayan satırlar silinir.
 *  sync_session_revocations Uzaktan kapatma istekleri. Cihaz bir sonraki raporun yanıtında alır, yerel oturumu
 *                           siler ve onaylar (status: pending → done). Cihaz çevrimdışıysa istek bekler.
 *
 * 'sync_' önekli tablolar SyncRegistry'de otomatik SYSTEM türüdür (eşitlenmez, düğüme özeldir).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_device_sessions')) {
            Schema::create('sync_device_sessions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('device_id');
                $t->unsignedBigInteger('user_id')->index();
                $t->char('session_hash', 64);                    // sha256(yerel oturum kimliği) — ham kimlik asla
                $t->timestamp('last_activity_at')->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamps();
                $t->unique(['device_id', 'session_hash']);
            });
        }

        if (! Schema::hasTable('sync_session_revocations')) {
            Schema::create('sync_session_revocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('device_id');
                $t->unsignedBigInteger('user_id');
                $t->char('session_hash', 64);
                $t->string('status', 12)->default('pending');   // pending | done
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->timestamp('done_at')->nullable();
                $t->timestamps();
                $t->index(['device_id', 'status']);
                $t->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_session_revocations');
        Schema::dropIfExists('sync_device_sessions');
    }
};
