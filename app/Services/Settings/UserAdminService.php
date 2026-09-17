<?php

namespace App\Services\Settings;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Support\Audit;
use App\Support\Staff\LastSuperAdminGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserAdminService
{
    public const FIELDS = ['name', 'username', 'email', 'phone', 'user_type', 'branch_id'];

    /** @param array $data  kullanıcı alanları + roles: string[] + password?: string */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $password = $data['password'] ?? Str::password(12, symbols: false);
            $tempPassword = empty($data['password']) ? $password : null;

            $user = User::query()->create(Arr::only($data, self::FIELDS) + [
                'password' => Hash::make($password),
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $user->syncRoles($data['roles'] ?? []);
            Audit::log('user.created', "{$user->name} ({$user->username}) kullanıcısını oluşturdu.", $user, ['after' => ['roles' => $data['roles'] ?? []]]);

            return ['user' => $user->fresh(), 'temp_password' => $tempPassword];
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $beforeRoles = $user->getRoleNames()->all();
            $user->fill(Arr::only($data, self::FIELDS));
            $user->save();
            $changes = Audit::diff($user);

            if (array_key_exists('roles', $data)) {
                $wasSuperAdmin = $user->hasRole('super-admin');
                $staysSuperAdmin = in_array('super-admin', $data['roles'], true);
                if (LastSuperAdminGuard::blocksChange($this->activeSuperAdminCount(), $wasSuperAdmin, $staysSuperAdmin)) {
                    throw new BusinessRuleException('Sistemde en az bir Sistem Yöneticisi kalmalıdır. Bu kullanıcının rolü kaldırılamaz.', 'last_super_admin');
                }
                $user->syncRoles($data['roles']);
                if ($beforeRoles !== $data['roles']) {
                    $changes['before']['roles'] = $beforeRoles;
                    $changes['after']['roles'] = $data['roles'];
                }
            }

            if ($changes['after'] !== []) {
                Audit::log('user.updated', "{$user->name} kullanıcı bilgilerini güncelledi.", $user, $changes);
            }

            return $user->fresh();
        });
    }

    public function toggleActive(User $user, bool $active, User $actingUser): User
    {
        if (! $active) {
            if ($user->id === $actingUser->id) {
                throw new BusinessRuleException('Kendi hesabınızı pasife alamazsınız.', 'self_deactivate');
            }
            $wasSuperAdmin = $user->hasRole('super-admin');
            if (LastSuperAdminGuard::blocksChange($this->activeSuperAdminCount(), $wasSuperAdmin, staysSuperAdmin: false)) {
                throw new BusinessRuleException('Sistemdeki son Sistem Yöneticisi pasife alınamaz.', 'last_super_admin');
            }
        }

        DB::transaction(function () use ($user, $active) {
            $user->forceFill(['is_active' => $active])->save();
            if (! $active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
            }
        });

        Audit::log($active ? 'user.activated' : 'user.deactivated', "{$user->name} kullanıcısını ".($active ? 'aktif' : 'pasif')." yaptı.", $user);

        return $user->fresh();
    }

    /** @return array{temp_password: string} */
    public function resetPassword(User $user): array
    {
        $tempPassword = Str::password(12, symbols: false);

        DB::transaction(function () use ($user, $tempPassword) {
            $user->forceFill(['password' => Hash::make($tempPassword), 'must_change_password' => true])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
        });

        Audit::log('user.password_reset', "{$user->name} kullanıcısının parolasını sıfırladı.", $user);

        return ['temp_password' => $tempPassword];
    }

    private function activeSuperAdminCount(): int
    {
        return User::role('super-admin')->where('is_active', true)->count();
    }
}
