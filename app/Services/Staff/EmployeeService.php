<?php

namespace App\Services\Staff;

use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmployeeService
{
    public const FIELDS = ['first_name', 'last_name', 'position', 'phone', 'email', 'hired_on', 'is_active'];

    /** @param array $data personel alanları + link_user_id?: int|null + create_user?: bool + username?: string */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $employee = new Employee(Arr::only($data, self::FIELDS));
            $employee->is_active ??= true;
            // Offline/hızlı ekleme güvencesi: pozisyon opsiyoneldir; NOT NULL kolon boş bırakılınca çökmesin.
            $employee->position ??= '';
            $employee->save();

            $tempPassword = null;
            if (! empty($data['link_user_id'])) {
                $this->linkUser($employee, (int) $data['link_user_id']);
            } elseif (! empty($data['create_user'])) {
                $tempPassword = $this->createUser($employee, $data['username'] ?? null);
            }

            Audit::log('employee.created', "{$employee->full_name} personel kaydını oluşturdu.", $employee);

            return ['employee' => $employee->fresh(), 'temp_password' => $tempPassword];
        });
    }

    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $employee->fill(Arr::only($data, self::FIELDS));
            // Güncellemede pozisyon boşaltılırsa NOT NULL çökmesin.
            $employee->position ??= '';
            $employee->save();
            $changes = Audit::diff($employee);

            if (array_key_exists('link_user_id', $data)) {
                if ($data['link_user_id']) {
                    $this->linkUser($employee, (int) $data['link_user_id']);
                } else {
                    $employee->forceFill(['user_id' => null])->save();
                }
            }

            if ($changes['after'] !== []) {
                Audit::log('employee.updated', "{$employee->full_name} personel bilgilerini güncelledi.", $employee, $changes);
            }

            return $employee->fresh();
        });
    }

    public function delete(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $name = $employee->full_name;
            $employee->delete();
            Audit::log('employee.deleted', "{$name} personel kaydını sildi.", $employee);
        });
    }

    private function linkUser(Employee $employee, int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $employee->forceFill(['user_id' => $user->id])->save();
    }

    private function createUser(Employee $employee, ?string $username): string
    {
        $username = $username ? Str::slug($username, '.') : Str::slug($employee->first_name.'.'.$employee->last_name, '.');
        $candidate = $username !== '' ? $username : 'personel';
        $i = 1;
        while (User::query()->where('username', $candidate)->withTrashed()->exists()) {
            $candidate = $username.$i;
            $i++;
        }
        $tempPassword = Str::password(12, symbols: false);

        $user = User::query()->create([
            'branch_id' => $employee->branch_id,
            'name' => $employee->full_name,
            'username' => $candidate,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'user_type' => User::TYPE_STAFF,
            'password' => Hash::make($tempPassword),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $employee->forceFill(['user_id' => $user->id])->save();

        return $tempPassword;
    }
}
