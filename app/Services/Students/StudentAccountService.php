<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\User;
use App\Services\Portal\PortalAccounts;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Öğrenci portal hesabı. Kullanıcı adı = öğrenci numarası; parola sistemce üretilir,
 * hash'i users.password'da, okunabilir kopyası users.initial_password'da ("encrypted" cast)
 * tutulur ki yetkili personel öğrenciye/veliye iletebilsin. Öğrenci şifresini değiştirince
 * okunabilir kopya silinir ve personel yalnız "sıfırla" diyebilir.
 * Başlangıç şifresiyle giriş yapan öğrenci kendi şifresini belirlemeden portalı kullanamaz
 * (must_change_password + EnsurePasswordChanged). Ayrılan/mezun öğrencinin hesabı kapalıdır.
 */
class StudentAccountService
{
    /** Karışmayan karakterler: 0/o, 1/l/i yok. */
    public const ALPHABET_LETTERS = 'abcdefghjkmnpqrstuvwxyz';

    public const ALPHABET_DIGITS = '23456789';

    public const PASSWORD_LENGTH = 7;

    public const ROLE = 'ogrenci';

    /** Kolay okunur başlangıç şifresi: küçük harf + rakam, en az 2 rakam ve 2 harf. */
    public static function generatePassword(int $length = self::PASSWORD_LENGTH): string
    {
        $letters = self::ALPHABET_LETTERS;
        $digits = self::ALPHABET_DIGITS;
        $all = $letters.$digits;

        do {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $all[random_int(0, strlen($all) - 1)];
            }
            $digitCount = preg_match_all('/[0-9]/', $out);
        } while ($digitCount < 2 || $digitCount > $length - 2);

        return $out;
    }

    /**
     * Öğrencinin portal hesabı yoksa açar (idempotent). Var olan hesaba dokunmaz.
     *
     * @return array{user: User, created: bool}
     */
    public function ensure(Student $student, bool $audit = true): array
    {
        if ($student->user_id && ($existing = User::query()->withTrashed()->find($student->user_id))) {
            return ['user' => $existing, 'created' => false];
        }

        return DB::transaction(function () use ($student, $audit) {
            $password = self::generatePassword();

            $user = new User;
            $user->forceFill([
                'branch_id' => $student->branch_id,
                'name' => $student->full_name ?: trim($student->first_name.' '.$student->last_name),
                'username' => $this->uniqueUsername($student),
                'email' => null,
                'phone' => null,
                'user_type' => User::TYPE_STUDENT,
                'password' => $password,
                'initial_password' => $password,
                'is_active' => self::shouldBeActive($student),
                'must_change_password' => true,
            ])->save();

            $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();
            if ($role) {
                $user->assignRole($role);
            }

            // Global kapsam/olay tetiklemeden bağla
            Student::query()->withoutGlobalScopes()->whereKey($student->id)->update(['user_id' => $user->id]);
            $student->setAttribute('user_id', $user->id);
            $student->syncOriginalAttribute('user_id');

            if ($audit) {
                Audit::log('student.account_created', "{$student->full_name} ({$student->student_no}) için öğrenci portal hesabı açtı.", $student);
            }

            return ['user' => $user, 'created' => true];
        });
    }

    /** Yeni başlangıç şifresi üretir, öğrencinin tüm oturumlarını kapatır. */
    public function resetPassword(Student $student): string
    {
        $user = $this->ensure($student)['user'];
        $password = self::generatePassword();

        DB::transaction(function () use ($user, $password, $student) {
            $user->forceFill([
                'password' => $password,
                'initial_password' => $password,
                'password_changed_at' => null,
                'must_change_password' => true,
            ])->save();

            PortalAccounts::revokeSessions($user->id);

            Audit::log('student.password_reset', "{$student->full_name} ({$student->student_no}) öğrencisinin portal şifresini sıfırladı.", $student);
        });

        return $password;
    }

    /** Personel görünümü için hesap özeti (şifre içermez). */
    public function summary(Student $student): array
    {
        $user = $student->user_id ? User::query()->withTrashed()->find($student->user_id) : null;

        return [
            'has_account' => (bool) $user,
            'username' => $user?->username,
            'is_active' => $user ? ($user->is_active && ! $user->trashed()) : false,
            'last_login_at' => $user?->last_login_at?->toAtomString(),
            'password_changed' => $user ? $user->initial_password === null : false,
            'password_changed_at' => $user?->password_changed_at?->toAtomString(),
        ];
    }

    /** Öğrenci adı değişince hesap adı da güncellenir. */
    public function syncName(Student $student): void
    {
        if ($student->user_id) {
            User::query()->whereKey($student->user_id)->where('user_type', User::TYPE_STUDENT)->update(['name' => $student->full_name]);
        }
    }

    /** Öğrenci silinince (arşiv) hesap pasife alınır ve oturumları kapanır. */
    public function deactivate(Student $student): void
    {
        if (! $student->user_id) {
            return;
        }
        PortalAccounts::setActive((int) $student->user_id, User::TYPE_STUDENT, false);
    }

    /** Hesap açık olmalı mı: silinmemiş ve ayrılmamış/mezun olmamış öğrenci. */
    public static function shouldBeActive(Student $student): bool
    {
        return ! $student->trashed() && ! PortalAccounts::isClosedStatus($student->status);
    }

    /**
     * Hesabın açık/kapalı durumunu öğrenci durumuyla eşitler (idempotent). Ayrıldı/Mezun → pasif +
     * oturumlar kapanır; tekrar Aktif/Donduruldu → yeniden açılır.
     *
     * @return bool durum değişti mi
     */
    public function syncActive(Student $student, bool $audit = true): bool
    {
        if (! $student->user_id) {
            return false;
        }
        $active = self::shouldBeActive($student);
        $changed = PortalAccounts::setActive((int) $student->user_id, User::TYPE_STUDENT, $active);

        if ($changed && $audit) {
            Audit::log($active ? 'student.account_reactivated' : 'student.account_deactivated',
                "{$student->full_name} ({$student->student_no}) öğrencisinin portal hesabı ".($active ? 'yeniden açıldı' : 'kapatıldı')
                .' (durum: '.(Student::STATUSES[$student->status] ?? $student->status).').', $student);
        }

        return $changed;
    }

    private function uniqueUsername(Student $student): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $student->student_no) ?: 'ogrenci'.$student->id;
        $candidate = $base;
        $i = 1;
        // Kullanıcı adları kurum genelinde tekildir; çakışmada şube kodu / sıra eklenir.
        while (User::query()->withTrashed()->where('username', $candidate)->exists()) {
            $candidate = $i === 1 && $student->branch?->code
                ? strtolower($student->branch->code).'-'.$base
                : $base.'-'.$i;
            $i++;
        }

        return mb_substr($candidate, 0, 60);
    }
}
