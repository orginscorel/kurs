<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| CİHAZ KEŞFİ + ADMS için `devices` tablosuna YALNIZ YENİ SÜTUN ekler.
| Var olan sütunlara dokunmaz, veri taşımaz, tamamen geri alınabilir (down → dropColumn).
|
| Neden gerekli?
|   device_model / vendor → ağ taramasında okunan künye ("YT33", "Perkotek") kaydedilsin;
|                           cihaz kartında model görünsün, elle yazılmasın.
|   adms_stamp            → ADMS'te cihazın gönderdiği son damga (aynı satırları tekrar
|                           istememek için cihazın kendi imleci).
|   discovered_at         → kayıt ağ taramasından mı geldi, elle mi girildi?
|
| `devices` eşitleme kayıt defterinde LOCAL olduğundan bu sütunlar da düğümler arasında
| taşınmaz (bkz. 2026_09_17_970100 migration'ının başlığı).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // "model" adı Eloquent tarafında karışıklık yaratmasın diye bilinçli olarak device_model.
            $table->string('device_model', 60)->nullable()->after('firmware');
            $table->string('vendor', 40)->nullable()->after('device_model');

            // ADMS (iclock) — cihazın kendi gönderim damgası
            $table->string('adms_stamp', 40)->nullable()->after('zk_last_record_count');

            // Ağ taramasıyla bulunup eklendiyse bulunma anı
            $table->timestamp('discovered_at')->nullable()->after('adms_stamp');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['device_model', 'vendor', 'adms_stamp', 'discovered_at']);
        });
    }
};
