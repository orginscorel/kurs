<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Terminal PUSH dinleyicisinin (kurs:terminal-dinle) aldığı HAM paketler — yalnız yerel düğüm (Mac).
|
| Ayrıştırılamayan paket SİLİNMEZ ("Unknown raw device event"); protokol ileride doğrulanınca buradan
| yeniden işlenebilir. Eşitleme kayıt defterinde LOCAL (app/Sync/SyncRegistry.php): sunucuya gitmez.
| Yalnız yeni tablo ekler; geri alınabilir; SQLite ve MySQL uyumlu (ham bayt base64 metin olarak saklanır).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_raw_packets', function (Blueprint $table) {
            $table->id();
            $table->string('source', 12)->default('push');             // push
            // Yön: device_to_bridge (yalnız dinleme) | device_to_upstream | upstream_to_device (aktarma kipi)
            $table->string('direction', 24)->default('device_to_bridge');
            $table->string('connection_id', 20)->index();                // aynı TCP bağlantısının parçaları
            $table->string('upstream', 60)->nullable();                  // aktarma hedefi (ör. 192.168.68.5:7005)
            $table->string('upstream_status', 30)->nullable();           // connected | refused | timeout | failed
            $table->unsignedBigInteger('device_id')->nullable()->index(); // kaynak IP = devices.zk_ip ise
            $table->string('remote_ip', 45);
            $table->unsignedInteger('remote_port')->nullable();
            $table->unsignedInteger('local_port')->nullable();
            $table->timestamp('received_at')->index();
            $table->unsignedInteger('byte_count')->default(0);
            $table->boolean('truncated')->default(false);                // 1 MB sınırında kesildi
            $table->char('sha256', 64)->index();
            $table->unsignedBigInteger('duplicate_of')->nullable();      // aynı baytlar daha önce geldiyse ilk kaydın id'si
            $table->string('format', 12);                                // http | binary | text | empty
            $table->string('http_method', 10)->nullable();
            $table->string('http_path', 500)->nullable();
            $table->text('http_headers')->nullable();
            $table->longText('http_body_base64')->nullable();
            $table->longText('payload_base64');
            $table->string('close_reason', 30)->nullable();
            $table->string('parse_status', 20)->default('unparsed');     // unparsed | parsed | failed
            $table->string('note', 190)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_raw_packets');
    }
};
