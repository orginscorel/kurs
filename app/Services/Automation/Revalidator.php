<?php

namespace App\Services\Automation;

use App\Models\Attendance;
use App\Models\Student;

/**
 * Gecikmeli eylemler çalışma anında tekrar denetlenir (ör. "derse gelmedi" bildirimi
 * gönderilmeden hemen önce öğretmen yoklamayı düzeltmiş olabilir, öğrenci bu arada
 * ayrılmış/dondurulmuş olabilir ya da aynı ders için "geç kaldı" mesajı zaten gitmiş olabilir).
 */
class Revalidator
{
    public static function stillValid(string $trigger, Student $student, array $context): bool
    {
        if (! StudentActivityGate::allows($trigger, $student->status)) {
            return false;
        }

        if (in_array($trigger, AttendanceMessageGuard::TRIGGERS, true) && ! empty($context['attendance_id'])
            && ! AttendanceMessageGuard::shouldSend($trigger, AttendanceMessageGuard::doneTriggersFor($student->id, (int) $context['attendance_id']))) {
            return false;
        }

        return match ($trigger) {
            'attendance.absent' => self::attendanceStillHasStatus($context, 'absent'),
            'attendance.late' => self::attendanceStillHasStatus($context, 'late'),
            default => true,
        };
    }

    private static function attendanceStillHasStatus(array $context, string $status): bool
    {
        if (empty($context['attendance_id'])) {
            return true;
        }

        $attendance = Attendance::query()->withoutGlobalScope('branch')->find($context['attendance_id']);

        return $attendance !== null && $attendance->status === $status;
    }
}
