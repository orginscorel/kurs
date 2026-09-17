<?php

namespace App\Services\Staff;

use App\Exceptions\BusinessRuleException;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class TeacherService
{
    public const FIELDS = [
        'first_name', 'last_name', 'title', 'specialty', 'phone', 'whatsapp_phone', 'email', 'color',
        'hired_on', 'employment_type', 'hourly_rate', 'max_weekly_hours', 'is_active', 'notes',
    ];

    /**
     * @param array $data  öğretmen alanları + subject_ids: int[] + create_user?: bool + username?, temp_password?
     * @return array{teacher: Teacher, temp_password: ?string}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $teacher = new Teacher(Arr::only($data, self::FIELDS));
            $teacher->is_active ??= true;
            $teacher->save();

            $this->syncSubjects($teacher, $data['subject_ids'] ?? []);

            $tempPassword = null;
            if (! empty($data['create_user'])) {
                $tempPassword = $this->attachUserAccount($teacher, $data['username'] ?? null);
            }

            Audit::log('teacher.created', "{$teacher->full_name} öğretmen kaydını oluşturdu.", $teacher);

            return ['teacher' => $teacher->fresh(), 'temp_password' => $tempPassword];
        });
    }

    public function update(Teacher $teacher, array $data): array
    {
        return DB::transaction(function () use ($teacher, $data) {
            $teacher->fill(Arr::only($data, self::FIELDS));
            $teacher->save();
            $changes = Audit::diff($teacher);

            if (array_key_exists('subject_ids', $data)) {
                $this->syncSubjects($teacher, $data['subject_ids'] ?? []);
            }

            $tempPassword = null;
            if (! empty($data['create_user']) && ! $teacher->user_id) {
                $tempPassword = $this->attachUserAccount($teacher, $data['username'] ?? null);
            }

            if ($changes['after'] !== []) {
                Audit::log('teacher.updated', "{$teacher->full_name} öğretmen bilgilerini güncelledi.", $teacher, $changes);
            }

            return ['teacher' => $teacher->fresh(), 'temp_password' => $tempPassword];
        });
    }

    public function delete(Teacher $teacher): void
    {
        if ($teacher->schedules()->exists() || $teacher->sessions()->where('date', '>=', now()->toDateString())->exists()) {
            throw new BusinessRuleException('Ders programında yer alan öğretmen silinemez. Önce durumunu "Pasif" yapın.', 'teacher_has_schedule');
        }

        DB::transaction(function () use ($teacher) {
            $name = $teacher->full_name;
            $teacher->delete();
            Audit::log('teacher.deleted', "{$name} öğretmen kaydını sildi.", $teacher);
        });
    }

    /** Sistem kullanıcısı (öğretmen portalı): geçici parola + 'ogretmen' rolü. İlk girişte parola değişimi zorunlu. */
    private function attachUserAccount(Teacher $teacher, ?string $username): string
    {
        if ($teacher->user_id) {
            throw new BusinessRuleException('Bu öğretmenin zaten bir sistem kullanıcısı var.', 'teacher_has_user');
        }

        $username = $username ? Str::slug($username, '.') : Str::slug($teacher->first_name.'.'.$teacher->last_name, '.');
        $username = $this->uniqueUsername($username);
        $tempPassword = Str::password(12, symbols: false);

        $user = User::query()->create([
            'branch_id' => $teacher->branch_id,
            'name' => $teacher->full_name,
            'username' => $username,
            'email' => $teacher->email,
            'phone' => $teacher->phone,
            'user_type' => User::TYPE_TEACHER,
            'password' => Hash::make($tempPassword),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        // Okunabilir kopya: "Portal girişi" panelinden yetkili personel görebilsin (öğretmen değiştirince silinir)
        $user->forceFill(['initial_password' => $tempPassword])->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole('ogretmen');

        $teacher->forceFill(['user_id' => $user->id])->save();
        Audit::log('teacher.user_created', "{$teacher->full_name} için sistem kullanıcısı oluşturdu ({$username}).", $teacher);

        return $tempPassword;
    }

    private function uniqueUsername(string $base): string
    {
        $base = $base !== '' ? $base : 'ogretmen';
        $candidate = $base;
        $i = 1;
        while (User::query()->where('username', $candidate)->withTrashed()->exists()) {
            $candidate = $base.$i;
            $i++;
        }

        return $candidate;
    }

    /** @param list<int> $subjectIds */
    private function syncSubjects(Teacher $teacher, array $subjectIds): void
    {
        $teacher->subjects()->sync($subjectIds);
    }
}
