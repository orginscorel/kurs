<?php

namespace App\Services\Teachers;

use App\Models\Teacher;
use App\Models\User;
use App\Services\Portal\PortalAccounts;
use App\Services\Students\StudentAccountService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Öğretmen portal hesabı. Kullanıcı adı = "ad.soyad" (mevcut öğretmen kullanıcılarıyla aynı düzen).
 * Başlangıç şifresi öğrenci/veli ile aynı kuralla üretilir, okunabilir kopyası users.initial_password'da
 * ("encrypted") tutulur; öğretmen kendi şifresini belirleyince silinir. Yeni hesap 'ogretmen' rolüyle açılır
 * (yalnız öğretmen portalı). Mevcut hesabın rolüne dokunulmaz.
 */
class TeacherAccountService
{
    public const ROLE = 'ogretmen';

    public const PASSWORD_LENGTH = 8;

    /** @return array{user: User, created: bool} */
    public function ensure(Teacher $teacher, bool $audit = true): array
    {
        if ($teacher->user_id && ($existing = User::query()->withTrashed()->find($teacher->user_id))) {
            return ['user' => $existing, 'created' => false];
        }

        return DB::transaction(function () use ($teacher, $audit) {
            $password = StudentAccountService::generatePassword(self::PASSWORD_LENGTH);

            $user = new User;
            $user->forceFill([
                'branch_id' => $teacher->branch_id,
                'name' => $teacher->full_name,
                'username' => $this->uniqueUsername($teacher),
                'email' => $teacher->email && ! User::query()->withTrashed()->where('email', $teacher->email)->exists() ? $teacher->email : null,
                'phone' => $teacher->phone,
                'user_type' => User::TYPE_TEACHER,
                'password' => $password,
                'initial_password' => $password,
                'is_active' => (bool) $teacher->is_active,
                'must_change_password' => true,
            ])->save();

            $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();
            if ($role) {
                $user->assignRole($role);
            }

            Teacher::query()->withoutGlobalScopes()->whereKey($teacher->id)->update(['user_id' => $user->id]);
            $teacher->setAttribute('user_id', $user->id);
            $teacher->syncOriginalAttribute('user_id');

            if ($audit) {
                Audit::log('teacher.account_created', "{$teacher->full_name} için öğretmen portal hesabı açtı ({$user->username}).", $teacher);
            }

            return ['user' => $user, 'created' => true];
        });
    }

    /** Yeni başlangıç şifresi; öğretmenin açık oturumları kapanır, ilk girişte yeniden şifre belirler. */
    public function resetPassword(Teacher $teacher): string
    {
        $user = $this->ensure($teacher)['user'];
        $password = StudentAccountService::generatePassword(self::PASSWORD_LENGTH);

        DB::transaction(function () use ($user, $password, $teacher) {
            $user->forceFill([
                'password' => $password,
                'initial_password' => $password,
                'password_changed_at' => null,
                'must_change_password' => true,
            ])->save();

            PortalAccounts::revokeSessions($user->id);

            Audit::log('teacher.password_reset', "{$teacher->full_name} öğretmeninin portal şifresini sıfırladı.", $teacher);
        });

        return $password;
    }

    public function user(Teacher $teacher): ?User
    {
        return $teacher->user_id ? User::query()->withTrashed()->find($teacher->user_id) : null;
    }

    /** Personel görünümü (şifre içermez). */
    public function summary(Teacher $teacher): array
    {
        $user = $this->user($teacher);

        return [
            'has_account' => (bool) $user,
            'username' => $user?->username,
            'is_active' => $user ? ($user->is_active && ! $user->trashed()) : false,
            'last_login_at' => $user?->last_login_at?->toAtomString(),
            // Başlangıç şifresi saklı değilse (eski hesap ya da öğretmen değiştirdi) "değiştirildi" sayılır
            'password_changed' => $user ? $user->initial_password === null : false,
            'password_changed_at' => $user?->password_changed_at?->toAtomString(),
            'portal_only' => $user ? $user->isTeacherPortalUser() : true,
            'roles' => $user ? $user->getRoleNames()->values() : [],
        ];
    }

    private function uniqueUsername(Teacher $teacher): string
    {
        $base = Str::slug($teacher->first_name.'.'.$teacher->last_name, '.') ?: 'ogretmen'.$teacher->id;
        $candidate = $base;
        $i = 1;
        while (User::query()->withTrashed()->where('username', $candidate)->exists()) {
            $candidate = $base.$i;
            $i++;
        }

        return mb_substr($candidate, 0, 60);
    }
}
