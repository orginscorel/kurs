<?php

namespace App\Listeners\Communication;

use App\Events\StudentMarkedAbsent;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\Automation\AttendanceMessageGuard;
use App\Services\Automation\AutomationEngine;
use App\Services\Webhooks\WebhookDispatcher;

/** Derse gelmediğinde veliye bildirim + webhook: trigger `attendance.absent` (kural gecikmesi ile gönderilir). */
class AutomateOnStudentAbsent
{
    public function handle(StudentMarkedAbsent $event): void
    {
        $attendance = Attendance::query()->withoutGlobalScope('branch')->with('session.subject')->find($event->attendanceId);
        if (! $attendance || $attendance->status !== 'absent') {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($attendance->student_id);
        if (! $student) {
            return;
        }

        $session = $attendance->session;

        if (! config('kurs.silent_events')) {
            app(WebhookDispatcher::class)->dispatch('attendance.absent', [
                'student_id' => $student->id, 'attendance_id' => $attendance->id, 'date' => (string) $attendance->date,
            ], $attendance->branch_id);
        }

        AutomationEngine::fire('attendance.absent', $student, [
            'ders_saati' => $session?->starts_at?->timezone('Europe/Istanbul')->format('H:i') ?? '',
            'ders_adi' => $session?->subject?->name ?? '',
        ], [
            'class_group_id' => $session?->class_group_id,
            'attendance_id' => $attendance->id,
            'dedupe_suffix' => AttendanceMessageGuard::suffix($attendance->id, 'attendance.absent'), // ders başına tek mesaj (geç kaldı ile ortak)
        ]);
    }
}
