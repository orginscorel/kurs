<?php

use App\Sync\SyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Çevrimdışı yerel kurulum ↔ web eşitlemesi (yalnız ekleme). Mimari: docs/SYNC.md
 *
 * Eşitlenen tablolara uuid (+ gerekiyorsa updated_at) sütunları SyncRegistry'den ÇALIŞMA ANINDA
 * eklenir; sonradan eklenen tablolar için `php artisan kurs:sync-prepare` aynı işi yapar (idempotent).
 * Mevcut satırların uuid'leri burada DOLDURULMAZ (canlıda parça parça: kurs:sync-prepare).
 */
return new class extends Migration
{
    private const PERMISSIONS = ['sync.use', 'sync.manage'];

    private const ROLE_GRANTS = [
        'yonetici' => ['sync.use', 'sync.manage'],
        'mudur' => ['sync.use', 'sync.manage'],
        'muhasebe' => ['sync.use'],
        'sistem' => ['sync.use', 'sync.manage'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('sync_devices')) {
            Schema::create('sync_devices', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('code', 12);                              // belge numarası öneki: D1, D2…
                $t->string('name', 120);
                $t->string('platform', 20);                          // windows|macos|linux|android|ios
                $t->string('mode', 10)->default('desktop');          // desktop (tam yerel) | mobile (önbellek + kuyruk)
                $t->string('app_version', 40)->nullable();
                $t->unsignedBigInteger('token_id')->nullable()->index();
                $t->text('public_key')->nullable();                  // X25519 (kurum anahtarı paketi için)
                $t->string('status', 12)->default('active');         // active | revoked
                $t->unsignedBigInteger('last_cursor')->default(0);
                $t->unsignedInteger('pending_reported')->default(0);
                $t->unsignedInteger('rejected_total')->default(0);
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('last_push_at')->nullable();
                $t->timestamp('last_pull_at')->nullable();
                $t->timestamp('key_issued_at')->nullable();
                $t->string('last_ip', 45)->nullable();
                $t->timestamp('paired_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->unsignedBigInteger('revoked_by')->nullable();
                $t->timestamps();
                $t->unique(['branch_id', 'code']);
            });
        }

        if (! Schema::hasTable('sync_changes')) {
            Schema::create('sync_changes', function (Blueprint $t) {
                $t->id();                                            // imleç (cursor)
                $t->uuid('change_uuid')->unique();                   // idempotent gönderim anahtarı
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('table_name', 64);
                $t->uuid('row_uuid');
                $t->string('op', 10);                                // insert|update|delete|upsert|merge|command
                $t->longText('fields')->nullable();                  // alan => değer (referanslar uuid)
                $t->string('source', 12)->default('web');            // web|device|sweep|command|conflict|local
                $t->unsignedBigInteger('origin_device_id')->nullable();
                $t->boolean('echo')->default(false);                 // kaynak cihaza da geri gönderilsin (çakışma kazananı)
                $t->unsignedBigInteger('user_id')->nullable();
                $t->uuid('command_uuid')->nullable();
                $t->string('status', 10)->nullable();                // yerel: null(bekliyor)|pushed|rejected
                $t->string('error', 300)->nullable();
                $t->timestamp('client_at', 3)->nullable();
                $t->timestamp('created_at', 3)->nullable();
                $t->index(['table_name', 'row_uuid', 'id']);
                $t->index(['branch_id', 'id']);
            });
        }

        if (! Schema::hasTable('sync_receipts')) {
            Schema::create('sync_receipts', function (Blueprint $t) {
                $t->uuid('change_uuid')->primary();
                $t->unsignedBigInteger('device_id')->index();
                $t->string('status', 12);                            // accepted|conflict|rejected
                $t->text('result')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('sync_conflicts')) {
            Schema::create('sync_conflicts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id')->nullable()->index();
                $t->unsignedBigInteger('device_id')->nullable();
                $t->string('kind', 12);                              // field|delete|attendance|finance|rejected
                $t->string('table_name', 64);
                $t->uuid('row_uuid')->nullable();
                $t->string('row_label', 200)->nullable();
                $t->string('field', 64)->nullable();
                $t->longText('device_value')->nullable();
                $t->longText('server_value')->nullable();
                $t->timestamp('device_at', 3)->nullable();
                $t->timestamp('server_at', 3)->nullable();
                $t->string('winner', 10)->nullable();                // device|server|both
                $t->string('status', 12)->default('open');           // open|resolved|dismissed
                $t->string('resolution', 30)->nullable();
                $t->string('note', 500)->nullable();
                $t->uuid('change_uuid')->nullable();
                $t->unsignedBigInteger('server_change_id')->nullable();
                $t->unsignedBigInteger('resolved_by')->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->timestamps();
                $t->index(['status', 'id']);
            });
        }

        if (! Schema::hasTable('sync_row_hashes')) {
            Schema::create('sync_row_hashes', function (Blueprint $t) {
                $t->string('table_name', 64);
                $t->string('row_key', 191);
                $t->uuid('row_uuid')->nullable();
                $t->char('hash', 32);
                $t->unsignedBigInteger('change_id')->default(0);
                $t->primary(['table_name', 'row_key']);
            });
        }

        if (! Schema::hasTable('sync_table_state')) {
            Schema::create('sync_table_state', function (Blueprint $t) {
                $t->string('table_name', 64)->primary();
                $t->timestamp('baselined_at')->nullable();
                $t->timestamp('last_swept_at')->nullable();
                $t->unsignedInteger('rows')->default(0);
                $t->string('fingerprint', 120)->nullable();          // hızlı süpürme: sayı + son id + son updated_at
            });
        }

        if (! Schema::hasTable('sync_number_blocks')) {
            Schema::create('sync_number_blocks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->unsignedBigInteger('device_id')->nullable()->index();
                $t->string('name', 60);
                $t->unsignedBigInteger('start');
                $t->unsignedBigInteger('end');
                $t->unsignedBigInteger('next');                      // yerel: sıradaki değer
                $t->timestamps();
                $t->index(['name', 'branch_id']);
            });
        }

        if (! Schema::hasTable('sync_tombstones')) {
            Schema::create('sync_tombstones', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('table_name', 64);
                $t->uuid('row_uuid');
                $t->unsignedBigInteger('change_id')->nullable();
                $t->timestamp('deleted_at')->nullable();
                $t->index(['table_name', 'row_uuid']);
            });
        }

        // Yerel düğüm: imleçler (transaction ile birlikte güncellenir) + ertelenen değişiklikler
        if (! Schema::hasTable('sync_state')) {
            Schema::create('sync_state', function (Blueprint $t) {
                $t->string('key', 64)->primary();
                $t->text('value')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('sync_deferred')) {
            Schema::create('sync_deferred', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('seq')->index();
                $t->longText('change');
                $t->unsignedInteger('attempts')->default(0);
                $t->string('last_error', 300)->nullable();
                $t->timestamps();
            });
        }

        // Eşitlenen tablolara uuid / updated_at (kayıt defterinden, çalışma anında)
        app(SyncSchema::class)->ensureColumns();

        $this->grantPermissions();
    }

    private function grantPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }
        $now = now();
        foreach (self::PERMISSIONS as $name) {
            if (! DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (self::ROLE_GRANTS as $role => $perms) {
            $roleId = DB::table('roles')->where('guard_name', 'web')->where('name', $role)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $perms)->pluck('id') as $pid) {
                if (! DB::table('role_has_permissions')->where('permission_id', $pid)->where('role_id', $roleId)->exists()) {
                    DB::table('role_has_permissions')->insert(['permission_id' => $pid, 'role_id' => $roleId]);
                }
            }
        }
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        foreach (['sync_deferred', 'sync_state', 'sync_tombstones', 'sync_number_blocks', 'sync_table_state',
            'sync_row_hashes', 'sync_conflicts', 'sync_receipts', 'sync_changes', 'sync_devices'] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
        // uuid / updated_at sütunları bilerek bırakılır (zararsız; veri kaybı olmasın).
    }
};
