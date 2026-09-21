<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perkotek YT33 "Dynamic Face" web paneli (HTTP /bin/cmd) yazma kanalı için kimlik bilgileri.
 * Cihaz IP'si zaten zk_ip'te; panel kullanıcı adı + port düz, panel şifresi ŞİFRELİ (DataEncrypted) durur.
 * Yalnız köprüyü çalıştıran yerel düğümde (Mac) anlamlıdır; SyncRegistry'de LOCAL kalır (düğümler arası taşınmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('panel_user')->nullable()->after('zk_last_record_count');
            $table->text('panel_password_encrypted')->nullable()->after('panel_user');
            $table->unsignedSmallInteger('panel_port')->nullable()->after('panel_password_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['panel_user', 'panel_password_encrypted', 'panel_port']);
        });
    }
};
