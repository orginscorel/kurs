<?php

namespace App\Listeners\Communication;

use App\Events\StudentEnteredBuilding;
use App\Models\AttendanceEvent;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Settings;

/** Kuruma girişte veliye bildirim + webhook: trigger `student.entry`. */
class AutomateOnStudentEntered
{
    public function handle(StudentEnteredBuilding $event): void
    {
        if (! $event->isLive) {
            return; // çevrimdışı kuyruktan geç senkronlanan olay bildirim üretmez
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
            app(WebhookDispatcher::class)->dispatch('student.entry', [
                'student_id' => $student->id, 'occurred_at' => $attendanceEvent->occurred_at?->toIso8601String(),
            ], $attendanceEvent->branch_id);
        }

        if (! Settings::get('attendance.notify_guardian_entry', true, $attendanceEvent->branch_id)) {
            return;
        }

        AutomationEngine::fire('student.entry', $student, [
            'saat' => $occurredAt?->format('H:i') ?? '',
        ], [
            'dedupe_suffix' => "entry:{$attendanceEvent->id}",
        ]);
    }
}
