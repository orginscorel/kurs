<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Veli portalı + rehberlikte "öğrenci portalında göster". Yalnız ekleme yapar:
 *  - guidance_meetings.visible_to_student (varsayılan false: mevcut kayıtlar öğrenciye kapalı kalır)
 *  - guardians.credentials / guardians.impersonate yetkileri, öğrenci eşdeğerlerinin verildiği rollere
 */
return new class extends Migration
{
    private const PERMISSIONS = ['guardians.credentials', 'guardians.impersonate'];

    private const ROLES = ['yonetici', 'mudur', 'danisman'];

    public function up(): void
    {
        if (! Schema::hasColumn('guidance_meetings', 'visible_to_student')) {
            Schema::table('guidance_meetings', function (Blueprint $table) {
                $table->boolean('visible_to_student')->default(false)->after('visibility');
            });
        }

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
        if (Schema::hasColumn('guidance_meetings', 'visible_to_student')) {
            Schema::table('guidance_meetings', function (Blueprint $table) {
                $table->dropColumn('visible_to_student');
            });
        }

        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
