<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Toplu e-posta / SMS gönderimi (kampanya). Şemaya yalnız EKLEME yapar:
 *  - message_campaigns: taslak → zamanlandı/gönderiliyor → tamamlandı | iptal
 *  - message_campaign_recipients: onay anında alınan alıcı listesi (kişi başı, kanal başı satır)
 *  - communication_suppressions: adres bazlı ret listesi (abonelikten çıkma, RET, elle)
 *  - outbound_messages: campaign_id, sms_parts, is_commercial
 * Yetkiler: messages.campaign / messages.campaign_send / messages.consents / integrations.sms / integrations.email
 */
return new class extends Migration
{
    /** izin => roller */
    private const GRANTS = [
        'messages.campaign' => ['yonetici', 'mudur', 'danisman'],
        'messages.campaign_send' => ['yonetici', 'mudur'],
        'messages.consents' => ['yonetici', 'mudur', 'danisman'],
        'integrations.sms' => ['yonetici', 'sistem'],
        'integrations.email' => ['yonetici', 'sistem'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('message_campaigns')) {
            Schema::create('message_campaigns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained();
                $table->string('name', 160);
                $table->json('channels');                          // ["sms","email"]
                $table->boolean('is_commercial')->default(false);  // ticari ileti (İYS) — yalnız onaylı alıcılar
                $table->json('audience');                          // hedef kitle filtreleri + elle eklenenler
                $table->json('options')->nullable();               // {sms_opt_out: bool} …
                $table->text('sms_body')->nullable();
                $table->string('email_subject', 200)->nullable();
                $table->text('email_body')->nullable();
                $table->string('status', 20)->default('draft');    // draft | scheduled | sending | completed | cancelled
                $table->dateTime('scheduled_at')->nullable();
                $table->json('estimate')->nullable();              // onay anındaki sayılar (kişi, SMS parça, kredi)
                $table->unsignedInteger('recipients_total')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['branch_id', 'status', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('message_campaign_recipients')) {
            Schema::create('message_campaign_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('message_campaigns')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained();
                $table->string('channel', 20);
                $table->string('recipient_type', 30)->nullable();  // student | guardian | teacher | employee | lead | null (elle)
                $table->unsignedBigInteger('recipient_id')->nullable();
                $table->unsignedBigInteger('student_id')->nullable();
                $table->string('group', 20);                       // students | guardians | teachers | employees | leads | manual
                $table->string('name', 160)->nullable();
                $table->string('to', 160)->nullable();
                $table->json('vars')->nullable();                  // kişiselleştirme değişkenleri
                $table->string('status', 12)->default('pending');  // pending | skipped | sending | sent | delivered | failed
                $table->string('skip_reason', 30)->nullable();     // no_address | no_consent | suppressed | duplicate | denied
                $table->unsignedTinyInteger('sms_parts')->nullable();
                $table->unsignedBigInteger('outbound_message_id')->nullable()->index();
                $table->string('error', 500)->nullable();
                $table->timestamps();
                $table->index(['campaign_id', 'status']);
                $table->index(['campaign_id', 'channel', 'status']);
            });
        }

        if (! Schema::hasTable('communication_suppressions')) {
            Schema::create('communication_suppressions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained();
                $table->string('channel', 20);                     // sms | email
                $table->string('address', 160);                    // 905xxxxxxxxx | e-posta (küçük harf)
                $table->string('reason', 30);                      // unsubscribe | ret | manual | bounce
                $table->string('source', 120)->nullable();
                $table->string('recipient_type', 30)->nullable();
                $table->unsignedBigInteger('recipient_id')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['branch_id', 'channel', 'address'], 'suppressions_unique');
            });
        }

        Schema::table('outbound_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('outbound_messages', 'campaign_id')) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('trigger')->index();
            }
            if (! Schema::hasColumn('outbound_messages', 'sms_parts')) {
                $table->unsignedTinyInteger('sms_parts')->nullable()->after('campaign_id');
            }
            if (! Schema::hasColumn('outbound_messages', 'is_commercial')) {
                $table->boolean('is_commercial')->default(false)->after('sms_parts');
            }
        });

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        foreach (self::GRANTS as $permission => $roles) {
            DB::table('permissions')->insertOrIgnore(['name' => $permission, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            $permissionId = DB::table('permissions')->where('guard_name', 'web')->where('name', $permission)->value('id');
            foreach (DB::table('roles')->where('guard_name', 'web')->whereIn('name', $roles)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        Schema::table('outbound_messages', function (Blueprint $table) {
            foreach (['is_commercial', 'sms_parts', 'campaign_id'] as $col) {
                if (Schema::hasColumn('outbound_messages', $col)) {
                    if ($col === 'campaign_id') {
                        $table->dropIndex(['campaign_id']);
                    }
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('communication_suppressions');
        Schema::dropIfExists('message_campaign_recipients');
        Schema::dropIfExists('message_campaigns');
    }
};
