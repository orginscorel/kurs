<?php

use App\Sync\ChangeRecorder;
use App\Sync\Sweeper;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * 3D DERSLİK TASARIMI VE OTURMA DÜZENİ — yalnız EKLEME yapar (mevcut tablolara dokunmaz).
 *
 *  classroom_layouts          derslik tasarımı: oda çokgeni, duvar/kapı/pencere, nesneler, masa-öğrenci ataması (data JSON)
 *                             Fiziksel odaya (classrooms) bağlanır; bağsız olabilir (ör. örnek derslik).
 *  classroom_layout_versions  her "Kaydet" bir sürüm (V1, V2…); geri yükleme yeni sürüm üretir.
 *
 * Öğrenciler data içinde öğrenci UUID'si ile tutulur (id'ler düğümler arasında değişir).
 * Yetki: classroom_layouts.manage (okuma academic.view) → yonetici + mudur.
 * Eşitleme: iki tablo da REFERENCE (iki yönlü, uuid) — app/Sync/SyncRegistry.php.
 * SQLite (masaüstü) ve MySQL uyumlu; idempotent; down() yalnız bu tabloları ve yetkiyi kaldırır.
 */
return new class extends Migration
{
    private const PERMISSION = 'classroom_layouts.manage';

    private const ROLES = ['yonetici', 'mudur'];

    private const TABLES = ['classroom_layouts', 'classroom_layout_versions'];

    public function up(): void
    {
        if (! Schema::hasTable('classroom_layouts')) {
            Schema::create('classroom_layouts', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->nullable()->unique();
                $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $t->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
                $t->string('name', 120);
                $t->unsignedInteger('version')->default(0);          // son kaydedilen sürüm no (0 = hiç kaydedilmedi)
                $t->boolean('is_active')->default(true);             // dersliğin kullanılan tasarımı
                $t->boolean('is_demo')->default(false);              // örnek kayıt (gerçek veriye karışmaz, silinebilir)
                $t->longText('data');                                // JSON: room/openings/objects/camera/settings
                $t->longText('thumbnail')->nullable();               // küçük görsel (data:image/jpeg;base64,…)
                $t->text('stats')->nullable();                       // JSON: alan, masa, kapasite, atanan…
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['branch_id', 'classroom_id']);
            });
        }

        if (! Schema::hasTable('classroom_layout_versions')) {
            Schema::create('classroom_layout_versions', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->nullable()->unique();
                $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $t->foreignId('classroom_layout_id')->constrained('classroom_layouts')->cascadeOnDelete();
                $t->unsignedInteger('version');
                $t->string('label', 120)->nullable();
                $t->longText('data');
                $t->text('stats')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['classroom_layout_id', 'version']);
            });
        }

        $this->grantPermission();
        $this->prepareSync();
    }

    private function grantPermission(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }
        $now = now();
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');
        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('roles')->whereIn('name', self::ROLES)->where('guard_name', 'web')->pluck('id') as $roleId) {
            $exists = DB::table('role_has_permissions')->where('permission_id', $permissionId)->where('role_id', $roleId)->exists();
            if (! $exists) {
                DB::table('role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Eşitleme hazırlığı (yeni ve boş tablolar): özet satırı; eşleşmiş masaüstü kurulumları bir kez anlık görüntüyle çeker. */
    private function prepareSync(): void
    {
        if (! Schema::hasTable('sync_changes') || ! Schema::hasTable('sync_table_state')) {
            return;
        }
        SyncRegistry::flush();
        app(SyncSchema::class)->flush();
        app(ChangeRecorder::class)->reset();
        app(Sweeper::class)->baseline(self::TABLES, true);
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->where('name', self::PERMISSION)->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        Schema::dropIfExists('classroom_layout_versions');
        Schema::dropIfExists('classroom_layouts');
        if (Schema::hasTable('sync_table_state')) {
            DB::table('sync_table_state')->whereIn('table_name', self::TABLES)->delete();
        }
        if (Schema::hasTable('sync_row_hashes')) {
            DB::table('sync_row_hashes')->whereIn('table_name', self::TABLES)->delete();
        }
    }
};
