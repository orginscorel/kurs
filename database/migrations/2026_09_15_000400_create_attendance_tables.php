<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biyometrik / RFID / QR terminaller. Yerel köprü (gateway) jetonla kimlik doğrular.
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->string('kind', 20);                      // fingerprint | rfid | qr | face | gateway
            $table->string('location', 120)->nullable();     // Ana giriş
            $table->string('direction', 10)->default('both'); // entry | exit | both
            $table->string('serial_no', 80)->nullable();
            $table->char('api_token_hash', 64)->unique();
            $table->string('api_token_prefix', 12);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('firmware', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        /*
         * Cihaz kullanıcı eşlemesi. KVKK: ham parmak izi şablonu ASLA merkeze gelmez;
         * cihazın kendi içindeki kullanıcı numarası (device_user_ref) öğrenciye bağlanır.
         */
        Schema::create('device_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->morphs('person');                        // Student | Teacher | Employee
            $table->string('kind', 20);                      // fingerprint | card | qr
            $table->string('identifier', 120);               // cihaz kullanıcı no / kart UID
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'kind', 'identifier']);
        });

        /*
         * Ham olay akışı. idempotency_key (gateway'in ürettiği UUID) tekil: çevrimdışı
         * kuyruk yeniden gönderdiğinde çift kayıt oluşmaz.
         */
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('person');
            $table->string('event_type', 10);                // ENTRY | EXIT
            $table->string('source', 20);                    // fingerprint | rfid | qr | manual | teacher
            $table->timestamp('occurred_at');
            $table->timestamp('received_at')->useCurrent();
            $table->string('idempotency_key', 80)->unique();
            $table->string('raw_identifier', 120)->nullable();
            $table->boolean('is_matched')->default(true);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['branch_id', 'occurred_at']);
            $table->index(['student_id', 'occurred_at']);
        });

        // Günlük özet: ilk giriş, son çıkış, içeride mi, kalma süresi.
        Schema::create('daily_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('first_entry_at')->nullable();
            $table->dateTime('last_exit_at')->nullable();
            $table->boolean('is_inside')->default(false);
            $table->unsignedInteger('minutes_inside')->default(0);
            $table->dateTime('last_event_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'date']);
            $table->index(['branch_id', 'date', 'is_inside']);
        });

        // Ders bazlı yoklama
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('lesson_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12);                    // present | absent | late | excused | medical
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->string('method', 20);                    // biometric | teacher | qr | rfid | admin | auto
            $table->string('note', 300)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('guardian_notified_at')->nullable();
            $table->timestamps();
            $table->unique(['lesson_session_id', 'student_id']);
            $table->index(['student_id', 'date']);
            $table->index(['branch_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['attendances', 'daily_presences', 'attendance_events', 'device_identities', 'devices'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
