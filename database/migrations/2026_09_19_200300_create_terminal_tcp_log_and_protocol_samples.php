<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Yerel düğüm (Mac) geliştirici/teşhis tabloları — ikisi de eşitleme kayıt defterinde LOCAL:
|   terminal_tcp_log            → PULL oturumlarının (ör. TCP 5005) TÜM TX/RX baytları + olayları (ms zaman damgası).
|   terminal_protocol_samples   → Protokol analizi örnekleri (TX/RX HEX, not, kaynak: uygulama/Wireshark/HAR).
| İkili veri yalnız HEX metin olarak saklanır (dizge kodlaması bozamaz). Yalnız yeni tablo; geri alınabilir.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_tcp_log', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 20)->index();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->string('occurred_at', 32);                 // ISO-8601 + milisaniye (2026-09-19T10:00:00.123+03:00)
            $table->unsignedInteger('t_ms')->default(0);       // oturum başından ms
            $table->string('kind', 12);                        // TX | RX | STATE
            $table->string('state', 24)->nullable();           // Connecting | Connected | Waiting Response | Data Received | Timeout | Connection Closed | Connection Reset | Error
            $table->string('source', 60)->nullable();          // kaynak uç ip:port
            $table->string('target', 60)->nullable();          // hedef uç ip:port
            $table->unsignedInteger('length')->default(0);
            $table->longText('payload_hex')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('terminal_protocol_samples', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);                       // işlem adı (ör. "Cihaz saati oku")
            $table->longText('tx_hex')->nullable();
            $table->longText('rx_hex')->nullable();
            $table->text('note')->nullable();
            $table->string('source', 20)->default('uygulama'); // uygulama | wireshark | har | elle
            $table->unsignedBigInteger('tcp_log_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_protocol_samples');
        Schema::dropIfExists('terminal_tcp_log');
    }
};
