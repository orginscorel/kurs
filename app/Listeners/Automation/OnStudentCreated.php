<?php

namespace App\Listeners\Automation;

use App\Events\StudentCreated;
use App\Models\Student;
use App\Services\Webhooks\WebhookDispatcher;

/** Öğrenci oluşturuldu → webhook `student.created` (KVKK: kimlik/telefon taşınmaz). */
class OnStudentCreated
{
    public function handle(StudentCreated $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($event->studentId);
        if (! $student) {
            return;
        }

        app(WebhookDispatcher::class)->dispatch('student.created', [
            'student_id' => $student->id, 'student_no' => $student->student_no, 'status' => $student->status,
            'registered_on' => $student->registered_on?->toDateString(),
        ], $student->branch_id);
    }
}
