<?php

namespace App\Listeners\Communication;

use App\Events\StudentLeftBuilding;
use App\Models\AttendanceEvent;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Settings;

/** Kurumdan çıkışta veliye bildirim + webhook: trigger `student.exit`. */
class AutomateOnStudentLeft
{
    public function handle(StudentLeftBuilding $event): void
    {
        if (! $event->isLive) {
            return;
        }

        $attendanceEvent = AttendanceEvent::query()->find($event->attendanceEventId);
        if (! $attendanceEvent || ! $attendanceEvent->student_id) {
            return;
        }

        $student = Student::query()->find($attendanceEvent->student_id);
        if (! $student) {
            return;
        }

        $occurredAt = $attendanceEvent->occurred_at?->timezone('Europe/Istanbul');

        if (! config('kurs.silent_events')) {
            app(WebhookDispatcher::class)->dispatch('student.exit', [
                'student_id' => $student->id, 'occurred_at' => $attendanceEvent->occurred_at?->toIso8601String(),
            ], $attendanceEvent->branch_id);
        }

        if (! Settings::get('attendance.notify_guardian_exit', true, $attendanceEvent->branch_id)) {
            return;
        }

        AutomationEngine::fire('student.exit', $student, [
            'saat' => $occurredAt?->format('H:i') ?? '',
        ], [
            'dedupe_suffix' => "exit:{$attendanceEvent->id}",
        ]);
    }
}
