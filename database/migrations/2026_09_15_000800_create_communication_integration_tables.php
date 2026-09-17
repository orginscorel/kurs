<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);                       // guardian.absent, payment.overdue …
            $table->string('channel', 20);                   // whatsapp | sms | email | push
            $table->string('name', 120);
            $table->string('subject', 200)->nullable();
            $table->text('body');                            // {{ogrenci_adi}} değişkenleri
            $table->string('provider_template', 120)->nullable(); // Meta onaylı şablon adı
            $table->string('language', 10)->default('tr');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'key', 'channel']);
        });

        /*
         * Giden mesaj kuyruğu. İstek sırasında gönderilmez; iş kuyruğu gönderir.
         * Durum: queued → sending → sent → delivered → read | failed
         */
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('channel', 20);
            $table->string('to', 160);
            $table->nullableMorphs('recipient');             // Guardian | Student | Teacher | Lead
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->string('template_key', 60)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->string('media_path')->nullable();        // görsel rapor PNG
            $table->string('status', 12)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider', 40)->nullable();
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->string('error', 1000)->nullable();
            $table->string('dedupe_key', 120)->nullable()->unique(); // aynı olay için tek mesaj
            $table->dateTime('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 60)->nullable();       // manual | automation:12 | announcement:4
            $table->timestamps();
            $table->index(['branch_id', 'status', 'scheduled_at']);
            $table->index(['branch_id', 'created_at']);
        });

        // İletişim izni (KVKK / ticari ileti). Yoksa yalnızca bilgilendirme mesajları gider.
        Schema::create('communication_consents', function (Blueprint $table) {
            $table->id();
            $table->morphs('consentable');                   // Guardian | Student | Lead
            $table->string('channel', 20);
            $table->string('purpose', 20);                   // informational | marketing
            $table->boolean('granted');
            $table->string('source', 60)->nullable();        // kayıt formu, sözlü, web
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['consentable_type', 'consentable_id', 'channel', 'purpose'], 'consents_unique');
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 30);                      // whatsapp | sms | email | payment | optical | biometric | storage
            $table->string('provider', 40);                  // meta_cloud | generic_http | netgsm | smtp | s3 …
            $table->text('config_encrypted')->nullable();    // API anahtarları şifreli
            $table->string('status', 20)->default('disconnected'); // connected | disconnected | error
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'kind']);
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->json('events');                          // ["payment.received", …]
            $table->text('secret_encrypted');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_body', 1000)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 12)->default('pending'); // pending | success | failed
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        /*
         * Otomasyon kuralı: tetikleyici (olay veya zaman koşulu) + koşullar + eylemler.
         * trigger: attendance.absent | attendance.late | student.entry | student.exit |
         *          installment.overdue | installment.upcoming | exam.result_published | homework.due
         */
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 160);
            $table->string('trigger', 60)->index();
            $table->json('conditions')->nullable();          // {"days_offset":3} {"program_ids":[..]}
            $table->json('actions');                         // [{"type":"whatsapp","to":"guardian","template":"guardian.absent"}]
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 12);                    // scheduled | done | skipped | failed
            $table->dateTime('run_at');
            $table->string('result', 1000)->nullable();
            $table->string('dedupe_key', 160)->unique();
            $table->timestamps();
            $table->index(['status', 'run_at']);
        });

        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10);                  // ios | android | web
            $table->string('token', 255)->unique();
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['push_tokens', 'automation_runs', 'automation_rules', 'webhook_deliveries', 'webhooks', 'integrations', 'communication_consents', 'outbound_messages', 'message_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
