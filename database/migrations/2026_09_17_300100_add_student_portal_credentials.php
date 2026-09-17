<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Öğrenci portalı: sistemin ürettiği başlangıç şifresi (Laravel "encrypted" cast ile şifreli)
 * ve öğrencinin kendi şifresini değiştirdiği an. Yeni yetkiler mevcut rollere eklenir
 * (CoreSeeder var olan rolleri ezmediği için burada yazılır). Yalnız ekleme yapar.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['students.credentials', 'students.impersonate'];

    private const ROLES = ['yonetici', 'mudur', 'danisman'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'initial_password')) {
                $table->text('initial_password')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
            }
        });

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        $permissionIds = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('id');
        $roleIds = DB::table('roles')->where('guard_name', 'web')->whereIn('name', self::ROLES)->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['initial_password', 'password_changed_at']);
        });

        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
