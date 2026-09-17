<?php

namespace App\Listeners\Automation;

use App\Events\StudentMarkedLate;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\Automation\AttendanceMessageGuard;
use App\Services\Automation\AutomationEngine;
use App\Services\Webhooks\WebhookDispatcher;

/** Derse geç kalma → webhook `attendance.late` + trigger `attendance.late` ({{gec_dakika}} dolu). */
class AutomateOnStudentLate
{
    public function handle(StudentMarkedLate $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $attendance = Attendance::query()->withoutGlobalScope('branch')->with('session.subject')->find($event->attendanceId);
        if (! $attendance || $attendance->status !== 'late') {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($attendance->student_id);
        if (! $student) {
            return;
        }

        $session = $attendance->session;

        app(WebhookDispatcher::class)->dispatch('attendance.late', [
            'student_id' => $student->id, 'attendance_id' => $attendance->id, 'date' => $attendance->date?->toDateString(),
            'late_minutes' => $attendance->late_minutes,
        ], $attendance->branch_id);

        AutomationEngine::fire('attendance.late', $student, [
            'ders_saati' => $session?->starts_at?->timezone('Europe/Istanbul')->format('H:i') ?? '',
            'ders_adi' => $session?->subject?->name ?? '',
            'gec_dakika' => $attendance->late_minutes !== null ? (string) $attendance->late_minutes : '',
        ], [
            'class_group_id' => $session?->class_group_id,
            'attendance_id' => $attendance->id,
            'dedupe_suffix' => AttendanceMessageGuard::suffix($attendance->id, 'attendance.late'),
        ]);
    }
}
