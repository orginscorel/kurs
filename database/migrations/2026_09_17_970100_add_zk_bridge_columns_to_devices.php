<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Biyometrik terminal köprüsü (Perkotek YT-33 / ZKTeco ailesi) için `devices` tablosuna
| YALNIZ YENİ SÜTUN ekler. Var olan sütunlara dokunmaz, veri taşımaz, geri alınabilir.
|
| `devices` eşitleme kayıt defterinde LOCAL (app/Sync/SyncRegistry.php) olduğundan bu sütunlar
| düğümler arasında taşınmaz: cihaz IP'si ve iletişim şifresi yalnız köprüyü çalıştıran
| düğümde (Mac masaüstü, KURS_NODE=local) tutulur. Bu bilinçli bir güvenlik sınırıdır.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Hangi konuşma biçimi: 'zk' = ZKTeco UDP/TCP 4370, 'adms' = cihaz bize HTTP POST atar, null = eski köprü jetonu.
            $table->string('protocol', 20)->nullable()->after('kind');

            // LAN bağlantı bilgileri (yalnız köprünün çalıştığı düğümde anlamlı)
            $table->string('zk_ip', 45)->nullable()->after('firmware');
            $table->unsignedSmallInteger('zk_port')->default(4370)->after('zk_ip');
            $table->string('zk_transport', 3)->default('tcp')->after('zk_port');   // tcp | udp
            $table->text('zk_comm_key_encrypted')->nullable()->after('zk_transport'); // cihaz "İletişim şifresi" (şifreli)

            // "Yalnız yeni kayıtlar" imleci: son işlenen kaydın zamanı + o saniyedeki son anahtar
            $table->timestamp('zk_cursor_at')->nullable()->after('zk_comm_key_encrypted');
            $table->string('zk_cursor_key', 120)->nullable()->after('zk_cursor_at');

            // Son çekme denemesinin sonucu (ekranda "cihaz sessiz mi?" teşhisi için)
            $table->timestamp('zk_last_pull_at')->nullable()->after('zk_cursor_key');
            $table->string('zk_last_status', 20)->nullable()->after('zk_last_pull_at');   // ok | error
            $table->string('zk_last_error', 300)->nullable()->after('zk_last_status');
            $table->unsignedInteger('zk_last_record_count')->default(0)->after('zk_last_error');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'protocol', 'zk_ip', 'zk_port', 'zk_transport', 'zk_comm_key_encrypted',
                'zk_cursor_at', 'zk_cursor_key', 'zk_last_pull_at', 'zk_last_status',
                'zk_last_error', 'zk_last_record_count',
            ]);
        });
    }
};
