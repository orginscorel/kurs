<?php

namespace App\Services\Guardians;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\Portal\PortalAccounts;
use App\Services\Students\StudentAccountService;
use App\Support\Audit;
use App\Support\Sensitive;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Veli portal hesabı. Kullanıcı adı = velinin cep telefonu (05xxxxxxxxx, normalize edilmiş).
 * Başlangıç şifresi öğrencideki gibi sistemce üretilir ve şifreli saklanır (users.initial_password).
 *
 * Güvenlik kararları:
 *  - Geçerli bir Türkiye cep telefonu yoksa hesap AÇILMAZ (problem: phone_missing).
 *  - Aynı numara başka bir hesapta kullanılıyorsa hesap AÇILMAZ ve kimse başka birinin hesabına
 *    bağlanmaz (problem: phone_conflict) — kayıtların personelce düzeltilmesi gerekir.
 *  - Hesap, velinin en az bir çocuğu açıkken (silinmemiş, ayrılmamış/mezun değil) aktiftir.
 */
class GuardianAccountService
{
    public const ROLE = 'veli';

    public const PROBLEM_PHONE_MISSING = 'phone_missing';

    public const PROBLEM_PHONE_CONFLICT = 'phone_conflict';

    /** Geçerli TR cep telefonundan kullanıcı adı ("05321234567"); değilse null. */
    public static function usernameFor(?string $phone): ?string
    {
        $normalized = Sensitive::normalizePhone($phone);

        return $normalized && preg_match('/^905\d{9}$/', $normalized) ? '0'.substr($normalized, 2) : null;
    }

    /** Velinin kullanıcı adı olabilecek numarası: önce telefon, yoksa WhatsApp numarası. */
    public static function desiredUsername(Guardian $guardian): ?string
    {
        return self::usernameFor($guardian->phone) ?? self::usernameFor($guardian->whatsapp_phone);
    }

    public function user(Guardian $guardian): ?User
    {
        return $guardian->user_id ? User::query()->withTrashed()->find($guardian->user_id) : null;
    }

    /**
     * Hesap yoksa açar (idempotent). Var olan hesaba dokunmaz.
     *
     * @return array{user: ?User, created: bool, problem: ?string, conflict: ?array}
     */
    public function ensure(Guardian $guardian, bool $audit = true): array
    {
        if ($existing = $this->user($guardian)) {
            return ['user' => $existing, 'created' => false, 'problem' => null, 'conflict' => null];
        }

        $username = self::desiredUsername($guardian);
        if (! $username) {
            return ['user' => null, 'created' => false, 'problem' => self::PROBLEM_PHONE_MISSING, 'conflict' => null];
        }
        if ($conflict = $this->conflictFor($username, $guardian)) {
            return ['user' => null, 'created' => false, 'problem' => self::PROBLEM_PHONE_CONFLICT, 'conflict' => $conflict];
        }

        return DB::transaction(function () use ($guardian, $username, $audit) {
            $password = StudentAccountService::generatePassword();

            $user = new User;
            $user->forceFill([
                'branch_id' => $guardian->branch_id,
                'name' => $guardian->full_name,
                'username' => $username,
                'email' => null,
                'phone' => $username,
                'user_type' => User::TYPE_GUARDIAN,
                'password' => $password,
                'initial_password' => $password,
                'is_active' => $this->shouldBeActive($guardian),
                'must_change_password' => true,
            ])->save();

            $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();
            if ($role) {
                $user->assignRole($role);
            }

            Guardian::query()->withoutGlobalScopes()->whereKey($guardian->id)->update(['user_id' => $user->id]);
            $guardian->setAttribute('user_id', $user->id);
            $guardian->syncOriginalAttribute('user_id');

            if ($audit) {
                Audit::log('guardian.account_created', "{$guardian->full_name} için veli portal hesabı açtı.", $guardian);
            }

            return ['user' => $user, 'created' => true, 'problem' => null, 'conflict' => null];
        });
    }

    /** Kullanıcı adı başka bir hesapta mı? Çakışan hesabın (varsa velinin) özeti. */
    public function conflictFor(string $username, Guardian $guardian): ?array
    {
        $other = User::query()->withTrashed()->where('username', $username)
            ->when($guardian->user_id, fn ($q) => $q->whereKeyNot($guardian->user_id))->first(['id', 'user_type']);
        if (! $other) {
            return null;
        }
        $otherGuardian = $other->user_type === User::TYPE_GUARDIAN
            ? Guardian::query()->withoutGlobalScopes()->where('user_id', $other->id)->first(['id', 'first_name', 'last_name'])
            : null;

        return [
            'guardian_id' => $otherGuardian?->id,
            'guardian_name' => $otherGuardian?->full_name,
            'is_guardian' => $other->user_type === User::TYPE_GUARDIAN,
        ];
    }

    /** Velinin açık (silinmemiş, ayrılmamış/mezun olmamış) en az bir çocuğu var mı? */
    public function shouldBeActive(Guardian $guardian): bool
    {
        if ($guardian->trashed()) {
            return false;
        }

        return DB::table('guardian_student as gs')->join('students as s', 's.id', '=', 'gs.student_id')
            ->where('gs.guardian_id', $guardian->id)->whereNull('s.deleted_at')
            ->whereNotIn('s.status', PortalAccounts::CLOSED_STATUSES)->exists();
    }

    /** @return bool durum değişti mi */
    public function syncActive(Guardian $guardian, bool $audit = true): bool
    {
        if (! $guardian->user_id) {
            return false;
        }
        $active = $this->shouldBeActive($guardian);
        $changed = PortalAccounts::setActive((int) $guardian->user_id, User::TYPE_GUARDIAN, $active);

        if ($changed && $audit) {
            Audit::log($active ? 'guardian.account_reactivated' : 'guardian.account_deactivated',
                "{$guardian->full_name} velisinin portal hesabı ".($active ? 'yeniden açıldı (açık öğrencisi var).' : 'kapatıldı (açık öğrencisi kalmadı).'), $guardian);
        }

        return $changed;
    }

    /** Öğrencinin tüm velileri için: hesap yoksa aç, aktifliği eşitle. */
    public function syncForStudent(Student $student, bool $audit = true): void
    {
        $ids = DB::table('guardian_student')->where('student_id', $student->id)->pluck('guardian_id');
        Guardian::query()->withoutGlobalScopes()->whereIn('id', $ids)->get()->each(function (Guardian $g) use ($audit) {
            if (! $g->trashed()) {
                $this->ensure($g, $audit);
            }
            $this->syncActive($g, $audit);
        });
    }

    /**
     * Veli telefonu değişince kullanıcı adı yeni numaraya taşınır (numara geçerli ve boşsa).
     *
     * @return ?string sorun kodu (yoksa null)
     */
    public function syncUsername(Guardian $guardian): ?string
    {
        $user = $this->user($guardian);
        if (! $user) {
            return $this->ensure($guardian)['problem'];
        }

        $username = self::desiredUsername($guardian);
        if (! $username) {
            return self::PROBLEM_PHONE_MISSING; // eski kullanıcı adı korunur
        }
        if ($username === $user->username) {
            return null;
        }
        if ($this->conflictFor($username, $guardian)) {
            return self::PROBLEM_PHONE_CONFLICT;
        }

        $old = $user->username;
        $user->forceFill(['username' => $username, 'phone' => $username])->save();
        Audit::log('guardian.username_changed', "{$guardian->full_name} velisinin portal kullanıcı adını telefon değişikliği nedeniyle güncelledi.", $guardian,
            ['before' => ['username' => $old], 'after' => ['username' => $username]]);

        return null;
    }

    public function syncName(Guardian $guardian): void
    {
        if ($guardian->user_id) {
            User::query()->whereKey($guardian->user_id)->where('user_type', User::TYPE_GUARDIAN)->update(['name' => $guardian->full_name]);
        }
    }

    /** Yeni başlangıç şifresi; tüm oturumlar kapanır, ilk girişte yeniden şifre belirleme zorunlu. */
    public function resetPassword(Guardian $guardian): string
    {
        $user = $this->user($guardian);
        if (! $user) {
            throw new \App\Exceptions\BusinessRuleException('Velinin portal hesabı henüz açılmamış.', 'guardian_account_missing', [], 404);
        }
        $password = StudentAccountService::generatePassword();

        DB::transaction(function () use ($user, $password, $guardian) {
            $user->forceFill([
                'password' => $password,
                'initial_password' => $password,
                'password_changed_at' => null,
                'must_change_password' => true,
            ])->save();

            PortalAccounts::revokeSessions($user->id);
            Audit::log('guardian.password_reset', "{$guardian->full_name} velisinin portal şifresini sıfırladı.", $guardian);
        });

        return $password;
    }

    /** Personel görünümü için hesap özeti (şifre içermez). */
    public function summary(Guardian $guardian): array
    {
        $user = $this->user($guardian);
        $desired = self::desiredUsername($guardian);
        $problem = null;
        $conflict = null;
        if (! $user) {
            if (! $desired) {
                $problem = self::PROBLEM_PHONE_MISSING;
            } elseif ($conflict = $this->conflictFor($desired, $guardian)) {
                $problem = self::PROBLEM_PHONE_CONFLICT;
            }
        } elseif ($desired && $desired !== $user->username && ($conflict = $this->conflictFor($desired, $guardian))) {
            $problem = self::PROBLEM_PHONE_CONFLICT;
        }

        return [
            'has_account' => (bool) $user,
            'username' => $user?->username,
            'desired_username' => $desired,
            'is_active' => $user ? ($user->is_active && ! $user->trashed()) : false,
            'should_be_active' => $this->shouldBeActive($guardian),
            'last_login_at' => $user?->last_login_at?->toAtomString(),
            'password_changed' => $user ? $user->getAttributes()['initial_password'] === null : false,
            'password_changed_at' => $user?->password_changed_at?->toAtomString(),
            'problem' => $problem,
            'conflict' => $conflict,
        ];
    }
}
