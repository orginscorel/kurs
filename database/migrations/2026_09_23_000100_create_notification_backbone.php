<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bildirim omurgası: olay×kitle şablon matrisi + manuel "taslak → önizleme (PDF) → onay → gönder" akışı.
 * Mevcut message_templates/outbound_messages altyapısını GENİŞLETİR (kopyalamaz):
 *  - message_templates'e event_type/audience meta sütunları (yer tutucu şablonları olay ve kitleye göre gruplar),
 *  - outbound_messages'a batch_id/audience (bir olay gönderiminin parçası + hangi kitle),
 *  - notification_batches (bir olay gönderimi: taslak/onaylı/gönderildi + PDF),
 *  - notification_settings (olay bazlı aç/kapa + onay gerekliliği + kanal/kitle tercihi).
 * Tümü additive ve SQLite-güvenli (yalnız Schema::create ve nullable sütun; ->change() yok).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('message_templates', 'event_type')) {
                $table->string('event_type', 40)->nullable()->after('key')->index();
            }
            if (! Schema::hasColumn('message_templates', 'audience')) {
                $table->string('audience', 12)->nullable()->after('event_type'); // student|parent|teacher|admin
            }
        });

        Schema::table('outbound_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('outbound_messages', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('campaign_id')->index();
            }
            if (! Schema::hasColumn('outbound_messages', 'audience')) {
                $table->string('audience', 12)->nullable()->after('recipient_id');
            }
        });

        Schema::create('notification_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('event_type', 40)->index();          // schedule.published, payment.receipt …
            $table->string('title', 200);
            $table->string('status', 12)->default('draft');      // draft | approved | sent | cancelled
            $table->json('audiences')->nullable();               // ['student','parent','teacher','admin']
            $table->json('context')->nullable();                 // öğrenci listesi, tarih, ekstra değişkenler
            $table->string('pdf_path')->nullable();              // storage/app içindeki önizleme PDF yolu
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'event_type']);
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('event_type', 40);
            $table->boolean('enabled')->default(true);
            $table->boolean('require_approval')->default(true);
            $table->json('channels')->nullable();                // ['whatsapp'] (ileride sms/email)
            $table->json('audiences')->nullable();               // varsayılan hedef kitleler
            $table->timestamps();
            $table->unique(['branch_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notification_batches');
        Schema::table('outbound_messages', function (Blueprint $table) {
            if (Schema::hasColumn('outbound_messages', 'batch_id')) {
                $table->dropColumn('batch_id');
            }
            if (Schema::hasColumn('outbound_messages', 'audience')) {
                $table->dropColumn('audience');
            }
        });
        Schema::table('message_templates', function (Blueprint $table) {
            foreach (['event_type', 'audience'] as $c) {
                if (Schema::hasColumn('message_templates', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
