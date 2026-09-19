<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Terminal ayarı: cihaz menüsündeki "Device / Machine ID" (boş = 1) ve bağlantı tipi
| ("pull" = LAN / TCP Pull: köprü cihaza bağlanır; "push" = Server / Push: cihaz köprüye bağlanır).
| Kurum geneli AYAR → eşitlenir (SyncRegistry › devices › exclude listesine EKLENMEZ).
| Yalnız nullable sütun ekler; geri alınabilir; SQLite uyumlu.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('machine_no')->nullable()->after('zk_transport');
            $table->string('terminal_connection', 8)->nullable()->after('machine_no');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['machine_no', 'terminal_connection']);
        });
    }
};
