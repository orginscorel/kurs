<?php

namespace App\Services\Notifications;

use App\Models\ClassGroup;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/** Uygulama bildirimi alacak personeli çözer (yetki bazlı yöneticiler, rehber öğretmen, sınıf danışmanı). */
class StaffRecipients
{
    /**
     * Şubedeki aktif personelden verilen yetkiye sahip olanlar (+ super-admin).
     *
     * @return list<int>
     */
    public static function withPermission(int $branchId, string $permission): array
    {
        $base = fn () => User::query()->where('is_active', true)->whereIn('user_type', [User::TYPE_STAFF, User::TYPE_TEACHER])
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'));

        $ids = [];
        if (Permission::query()->where('name', $permission)->exists()) {
            $ids = $base()->permission($permission)->pluck('id')->all();
        }
        try {
            $ids = array_merge($ids, $base()->role('super-admin')->pluck('id')->all());
        } catch (\Throwable) {
            // rol yoksa yok say
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Öğrencinin rehber öğretmeninin kullanıcı hesabı; yoksa sınıf danışmanınınki. */
    public static function counselorUserId(Student $student): ?int
    {
        if ($student->guidance_teacher_id) {
            $uid = Teacher::query()->withoutGlobalScope('branch')->whereKey($student->guidance_teacher_id)->value('user_id');
            if ($uid) {
                return (int) $uid;
            }
        }

        $advisorId = ClassGroup::query()->withoutGlobalScope('branch')
            ->join('class_group_student as cgs', 'cgs.class_group_id', '=', 'class_groups.id')
            ->where('cgs.student_id', $student->id)->whereNull('cgs.left_on')->whereNotNull('class_groups.advisor_teacher_id')
            ->value('class_groups.advisor_teacher_id');

        if ($advisorId) {
            $uid = Teacher::query()->withoutGlobalScope('branch')->whereKey($advisorId)->value('user_id');

            return $uid ? (int) $uid : null;
        }

        return null;
    }

    /** Rehber öğretmen; yoksa risk görme yetkili yöneticiler. @return list<int> */
    public static function counselorOrManagers(Student $student): array
    {
        $uid = self::counselorUserId($student);

        return $uid ? [$uid] : self::withPermission((int) $student->branch_id, 'risk.view');
    }
}
