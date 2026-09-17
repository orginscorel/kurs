<?php

namespace Tests\Unit;

use App\Services\Settings\RoleService;
use App\Support\Permissions;
use App\Support\Staff\LastSuperAdminGuard;
use PHPUnit\Framework\TestCase;

/**
 * Ayarlar modülü: yetki matrisi senkronu + "son süper yönetici" iş kuralı.
 * DB'den bağımsız saf mantık testleri.
 */
class SettingsStaffTest extends TestCase
{
    public function test_filter_valid_permissions_drops_unknown_keys_and_dedupes(): void
    {
        $catalog = Permissions::all();
        $requested = ['students.view', 'students.view', 'made_up.permission', 'teachers.manage'];

        $result = RoleService::filterValidPermissions($requested, $catalog);

        $this->assertSame(['students.view', 'teachers.manage'], $result);
    }

    public function test_filter_valid_permissions_matches_full_catalog_when_all_selected(): void
    {
        $catalog = Permissions::all();

        $result = RoleService::filterValidPermissions($catalog, $catalog);

        sort($catalog);
        sort($result);
        $this->assertSame($catalog, $result);
    }

    public function test_last_super_admin_cannot_lose_role_when_only_one_active(): void
    {
        // Tek aktif süper yönetici; rolünü kaldırmaya çalışıyor → engellenmeli
        $this->assertTrue(LastSuperAdminGuard::blocksChange(activeSuperAdminCount: 1, wasSuperAdmin: true, staysSuperAdmin: false));
    }

    public function test_super_admin_role_change_allowed_when_others_remain(): void
    {
        // İki aktif süper yönetici varken biri rolünü kaybedebilir
        $this->assertFalse(LastSuperAdminGuard::blocksChange(activeSuperAdminCount: 2, wasSuperAdmin: true, staysSuperAdmin: false));
    }

    public function test_keeping_super_admin_role_is_always_allowed(): void
    {
        $this->assertFalse(LastSuperAdminGuard::blocksChange(activeSuperAdminCount: 1, wasSuperAdmin: true, staysSuperAdmin: true));
    }

    public function test_non_super_admin_user_never_blocks_change(): void
    {
        $this->assertFalse(LastSuperAdminGuard::blocksChange(activeSuperAdminCount: 0, wasSuperAdmin: false, staysSuperAdmin: false));
    }
}
