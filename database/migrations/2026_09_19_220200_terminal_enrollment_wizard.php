<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Terminale kayıt sihirbazı: kişiye cihaz numarası ayrılır, kişi cihazda parmak/kart/yüz okuturken sihirbaz açık kalır.
| Cihaz "realtime_enroll_data" gönderince kayıt bu oturuma bağlanır — cihaz numarayı kendisi verse bile doğru kişiye.
| Her iki tablo da LOCAL (yalnız cihazı dinleyen Mac); sonuç device_identities ile eşitlenir. Yalnız ekler.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_enrollment_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('person_type', 20);
            $table->unsignedBigInteger('person_id');
            $table->string('reserved_no', 40);
            $table->string('final_no', 40)->nullable();
            $table->boolean('created_identity')->default(false);   // numara bu oturumda ayrıldı (iptalde geri alınır)
            $table->unsignedBigInteger('device_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('terminal_device_users', function (Blueprint $table) {
            $table->unsignedSmallInteger('face_count')->nullable()->after('fingerprint_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_enrollment_sessions');
        Schema::table('terminal_device_users', fn (Blueprint $t) => $t->dropColumn('face_count'));
    }
};
