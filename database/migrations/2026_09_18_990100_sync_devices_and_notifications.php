<?php

use App\Sync\ChangeRecorder;
use App\Sync\Sweeper;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `devices` (biyometrik terminal), `device_identities` (eşlemeler; artık yukarı da) ve `app_notifications` (bildirimler)
 * eşitlemeye katılıyor — YALNIZ EKLEME.
 * Kurallar: app/Sync/SyncRegistry.php, docs/SYNC.md › "Sonradan katılan tablolar".
 *
 *  devices            + uuid (boş olabilir, tekil) — başka sütun YOK
 *  sync_terminal_reports (yeni, SYSTEM) masaüstünün terminal durum raporu (hangi Mac, ne zaman, son çekme/sonuç/hata)
 *  app_notifications  + uuid (boş olabilir, tekil), + updated_at (okundu değişikliği süpürücünün hızlı kipinde görünsün)
 *
 * Veri: sunucuda mevcut satırlara uuid verilir ve satır özeti alınır (günlüğe yazılmaz; eşleşmiş masaüstü kurulumları
 * bu tabloları bir kez anlık görüntüyle çeker — LocalSyncEngine::lateTables). Yerel kurulumda (KURS_NODE=local) o ana
 * kadar yalnız bu Mac'te duran terminal kayıtları "eklendi" olarak günlüğe yazılır → ilk turda sunucuya çıkar.
 * Yerelde üretilmiş bildirimlere uuid verilmez (yerelde kalırlar).
 *
 * SQLite (masaüstü paketi her açılışta `migrate --force`) ve MySQL uyumlu; idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices') && ! Schema::hasColumn('devices', 'uuid')) {
            Schema::table('devices', fn (Blueprint $t) => $t->uuid('uuid')->nullable());
            Schema::table('devices', fn (Blueprint $t) => $t->unique('uuid', 'devices_uuid_unique'));
        }

        // Masaüstünün terminal durum raporu (sync_ öneki: SYSTEM, eşitlenmez). Cihaz başına, raporlayan kurulum başına tek satır.
        if (! Schema::hasTable('sync_terminal_reports')) {
            Schema::create('sync_terminal_reports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('terminal_id');                   // devices.id (bu düğümde)
                $t->unsignedBigInteger('sync_device_id');                // sync_devices.id (raporlayan masaüstü)
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('last_pull_at')->nullable();
                $t->string('status', 20)->nullable();                    // ok | error
                $t->string('error', 300)->nullable();
                $t->unsignedInteger('record_count')->default(0);
                $t->text('details')->nullable();                         // JSON: TerminalStatusProvider (sürücü, kip, kuyruk…)
                $t->timestamp('reported_at')->nullable();
                $t->timestamps();
                $t->unique(['terminal_id', 'sync_device_id']);
            });
        }

        if (Schema::hasTable('app_notifications')) {
            $hasUuid = Schema::hasColumn('app_notifications', 'uuid');
            $hasUpdated = Schema::hasColumn('app_notifications', 'updated_at');
            if (! $hasUuid || ! $hasUpdated) {
                Schema::table('app_notifications', function (Blueprint $t) use ($hasUuid, $hasUpdated) {
                    if (! $hasUuid) {
                        $t->uuid('uuid')->nullable();
                    }
                    if (! $hasUpdated) {
                        $t->timestamp('updated_at')->nullable();
                    }
                });
            }
            if (! $hasUuid) {
                Schema::table('app_notifications', fn (Blueprint $t) => $t->unique('uuid', 'app_notifications_uuid_unique'));
            }
        }

        $this->prepareSync();
    }

    /** Eşitleme hazırlığı: uuid doldurma + satır özeti (yalnız bu iki tablo). */
    private function prepareSync(): void
    {
        if (! Schema::hasTable('sync_changes') || ! Schema::hasTable('sync_table_state') || ! Schema::hasTable('devices')) {
            return;
        }
        SyncRegistry::flush();
        $schema = app(SyncSchema::class);
        $schema->flush();
        app(ChangeRecorder::class)->reset();
        $local = ChangeRecorder::isLocalNode();

        $schema->backfillUuids(300, null, $local ? ['devices'] : ['devices', 'app_notifications']);
        $schema->flush();

        $sweeper = app(Sweeper::class);
        if (! $local) {
            // Sunucu: özet (günlüğe yazmadan). Cihazlar bu tabloları ayrıca anlık görüntüyle çeker.
            $sweeper->baseline(['devices', 'device_identities', 'app_notifications'], true);

            return;
        }
        // Yerel: eşlemeler (device_identities) artık yukarı da gidiyor → özet; yalnız bu Mac'te duranlar ilk turda
        // sunucuya sorularak gönderilir (LocalSyncEngine::lateTables).
        $sweeper->baseline(['device_identities'], true);
        // Terminaller bu sürüme kadar hiç eşitlenmediği için yereldeki HER kayıt bu Mac'e özgüdür: "eklendi" olarak
        // hemen kuyruğa yazılır (sonra kaydedilen okutmalardan önce sunucuya çıksın). Bildirimler aşağı yönlü.
        $def = SyncRegistry::get('devices');
        if ($def && ! DB::table('sync_table_state')->where('table_name', 'devices')->whereNotNull('baselined_at')->exists()) {
            $recorded = DB::table('sync_state')->where('key', 'snapshot_done_at')->whereNotNull('value')->exists();
            $s = $sweeper->sweepTable($def, $recorded);
            DB::table('sync_table_state')->updateOrInsert(['table_name' => 'devices'], [
                'baselined_at' => now(), 'last_swept_at' => now(), 'rows' => $s['rows'], 'fingerprint' => $sweeper->fingerprint($def),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_terminal_reports');
        if (Schema::hasTable('app_notifications') && Schema::hasColumn('app_notifications', 'updated_at')) {
            Schema::table('app_notifications', fn (Blueprint $t) => $t->dropColumn('updated_at'));
        }
        // uuid sütunları bilerek bırakılır (950100 ile aynı ilke: zararsız, geri alma veri kaybettirmesin).
        // device_identities özeti bırakılır (önceki sürümde de aşağı yönlü eşitleniyordu)
        DB::table('sync_table_state')->whereIn('table_name', ['devices', 'app_notifications'])->delete();
        DB::table('sync_row_hashes')->whereIn('table_name', ['devices', 'app_notifications'])->delete();
    }
};
