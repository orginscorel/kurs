<?php

namespace App\Services\Settings;

use App\Exceptions\BusinessRuleException;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    public const PROTECTED_ROLE = 'super-admin';

    public function create(string $name, array $permissions): Role
    {
        if (Role::query()->where('name', $name)->exists()) {
            throw new BusinessRuleException('Bu isimde bir rol zaten var.', 'role_exists');
        }

        return DB::transaction(function () use ($name, $permissions) {
            $role = Role::create(['name' => $name, 'guard_name' => 'web']);
            $this->syncPermissions($role, $permissions);
            Audit::log('role.created', "\"{$name}\" rolünü oluşturdu.", $role, ['after' => ['permissions' => $permissions]]);

            return $role;
        });
    }

    public function copy(Role $source, string $newName): Role
    {
        return $this->create($newName, $source->permissions->pluck('name')->all());
    }

    public function update(Role $role, array $permissions): Role
    {
        $this->assertEditable($role);

        return DB::transaction(function () use ($role, $permissions) {
            $before = $role->permissions->pluck('name')->all();
            $this->syncPermissions($role, $permissions);
            if ($before !== $permissions) {
                Audit::log('role.permissions_updated', "\"{$role->name}\" rolünün yetkilerini güncelledi.", $role, ['before' => ['permissions' => $before], 'after' => ['permissions' => $permissions]]);
            }

            return $role->fresh('permissions');
        });
    }

    public function delete(Role $role): void
    {
        $this->assertEditable($role);

        if (DB::table('model_has_roles')->where('model_type', 'user')->where('role_id', $role->id)->exists()) {
            throw new BusinessRuleException('Bu role atanmış kullanıcılar var. Önce kullanıcıların rolünü değiştirin.', 'role_in_use');
        }

        $name = $role->name;
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Audit::log('role.deleted', "\"{$name}\" rolünü sildi.");
    }

    private function assertEditable(Role $role): void
    {
        if ($role->name === self::PROTECTED_ROLE) {
            throw new BusinessRuleException('Sistem Yöneticisi rolü düzenlenemez veya silinemez.', 'protected_role');
        }
    }

    /** @param list<string> $permissions */
    private function syncPermissions(Role $role, array $permissions): void
    {
        $valid = self::filterValidPermissions($permissions, Permissions::all());
        foreach ($valid as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $role->syncPermissions($valid);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Yetki matrisi senkronu: yalnızca Permissions::catalog()'daki geçerli anahtarlar bir role
     * yazılır — istemciden gelen bilinmeyen/eski bir anahtar sessizce elenir. Sıra ve yinelenenler normalize edilir.
     *
     * @param  list<string>  $requested
     * @param  list<string>  $catalogAll  Permissions::all()
     * @return list<string>
     */
    public static function filterValidPermissions(array $requested, array $catalogAll): array
    {
        return array_values(array_unique(array_intersect($requested, $catalogAll)));
    }
}
