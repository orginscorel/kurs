<?php

use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * PORTAL PAKET / KOÇLUK TALEPLERİ — yalnız EKLEME (additive), SQLite güvenli.
 *
 *  - package_requests : öğrenci/velinin portaldan oluşturduğu "paket ekle / yükselt" ya da "koçluk al"
 *    talebi. Online ödeme YOK; yalnız kayda düşer, yönetici kesinleştirir. status: pending|approved|rejected.
 *  - education_packages.has_coaching : paketin koçluk içerip içermediği (portalda "koçluk dahil" rozetini
 *    ve kayıt sonrası koç atama akışını kolaylaştırır). Nullable/additive; ->change() YOK.
 *
 * Ayrıca `package_requests.manage` yetkisini şu an `enrollments.create` yetkisi olan rollere verir
 * (paket taleplerini kesinleştirecek olan kayıt oluşturuculara).
 *
 * Eşitleme: package_requests = REFERENCE (iki yönlü, uuid) — app/Sync/SyncRegistry.php.
 */
return new class extends Migration
{
    private const PERMISSION = 'package_requests.manage';

    public function up(): void
    {
        if (! Schema::hasTable('package_requests')) {
            Schema::create('package_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                // 'package' talebinde dolu, 'coaching' (koçluk add-on) talebinde null
                $table->foreignId('package_id')->nullable()->constrained('education_packages')->nullOnDelete();
                $table->string('kind', 12)->default('package');      // package | coaching
                $table->string('note', 500)->nullable();             // talep sahibinin notu
                $table->string('status', 12)->default('pending');    // pending | approved | rejected
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('handled_at')->nullable();
                $table->string('decision_note', 500)->nullable();    // yöneticinin karar notu (onay/ret)
                $table->uuid('uuid')->nullable()->unique();          // eşitleme kimliği (REFERENCE)
                $table->timestamps();
                $table->softDeletes();
                $table->index(['branch_id', 'status']);
                $table->index(['student_id', 'status']);
            });
        }

        if (Schema::hasTable('education_packages') && ! Schema::hasColumn('education_packages', 'has_coaching')) {
            Schema::table('education_packages', function (Blueprint $table) {
                $table->boolean('has_coaching')->default(false)->after('includes');
            });
        }

        $this->syncPermission();

        // Yeni eşitlenen tablo + eklenen sütun: kayıt defteri ve şema önbelleğini tazele.
        SyncRegistry::flush();
        app(SyncSchema::class)->flush();
    }

    private function syncPermission(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $permId = DB::table('permissions')->where('guard_name', 'web')->where('name', self::PERMISSION)->value('id');
        if (! $permId) {
            return;
        }

        // Kaydı/paketi kesinleştiren rollere (enrollments.create) talep yönetimini de ver.
        $enrollPermId = DB::table('permissions')->where('guard_name', 'web')->where('name', 'enrollments.create')->value('id');
        $roleIds = $enrollPermId
            ? DB::table('role_has_permissions')->where('permission_id', $enrollPermId)->pluck('role_id')->all()
            : [];

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('package_requests');

        if (Schema::hasTable('education_packages') && Schema::hasColumn('education_packages', 'has_coaching')) {
            Schema::table('education_packages', function (Blueprint $table) {
                $table->dropColumn('has_coaching');
            });
        }

        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
            if ($id) {
                DB::table('role_has_permissions')->where('permission_id', $id)->delete();
                DB::table('model_has_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        }
    }
};
