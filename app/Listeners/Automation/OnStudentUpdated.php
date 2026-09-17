<?php

namespace App\Listeners\Automation;

use App\Events\StudentUpdated;
use App\Models\Student;
use App\Services\Webhooks\WebhookDispatcher;

/** Öğrenci güncellendi → webhook `student.updated` (yalnız değişen alan adları). */
class OnStudentUpdated
{
    public function handle(StudentUpdated $event): void
    {
        if (config('kurs.silent_events') || $event->fields === []) {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($event->studentId);
        if (! $student) {
            return;
        }

        app(WebhookDispatcher::class)->dispatch('student.updated', [
            'student_id' => $student->id, 'student_no' => $student->student_no, 'changed_fields' => array_values($event->fields),
        ], $student->branch_id);
    }
}
