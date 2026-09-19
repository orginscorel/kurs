<?php

use App\Services\Devices\Drivers\Yt33\Push\RealtimeProtocol;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Terminaldeki kullanıcılar (YT33 push "realtime_enroll_data" ile gelir) — PDKS'de ad göstermek ve otomatik eşleme
| sonucunu tutmak için. LOCAL (yalnız cihazı dinleyen Mac); eşleme sonucu device_identities ile eşitlenir.
| Biyometrik şablon SAKLANMAZ; yalnız sayısı.
|
| Ek: daha önce ham kaydedilmiş push paketlerindeki şablonlar gizlenir (1.15.1'de şablon ham pakette kalıyordu).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_device_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('device_id');
            $table->string('user_no', 40);
            $table->string('name', 120)->nullable();
            $table->string('card_no', 40)->nullable();
            $table->unsignedSmallInteger('privilege')->nullable();
            $table->unsignedSmallInteger('fingerprint_count')->nullable();
            $table->string('valid_from', 20)->nullable();
            $table->string('valid_until', 20)->nullable();
            $table->string('link_status', 20)->nullable();   // linked | existing | ambiguous | no_match | no_name
            $table->timestamp('last_enrolled_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'user_no']);
        });

        if (! Schema::hasTable('terminal_raw_packets')) {
            return;
        }

        DB::table('terminal_raw_packets')->where('format', 'http')->orderBy('id')->select(['id', 'payload_base64', 'http_body_base64', 'note'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $r) {
                    [$payload, $note] = RealtimeProtocol::redact((string) base64_decode($r->payload_base64));
                    if ($note === null) {
                        continue;
                    }
                    [$body] = $r->http_body_base64 !== null ? RealtimeProtocol::redact((string) base64_decode($r->http_body_base64)) : [null];
                    DB::table('terminal_raw_packets')->where('id', $r->id)->update([
                        'payload_base64' => base64_encode($payload),
                        'http_body_base64' => $body !== null ? base64_encode($body) : null,
                        'byte_count' => strlen($payload),
                        'sha256' => hash('sha256', $payload),
                        'note' => mb_substr($note, 0, 190),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_device_users');
    }
};
