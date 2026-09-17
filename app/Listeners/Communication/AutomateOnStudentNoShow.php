<?php

namespace App\Listeners\Communication;

use App\Events\StudentNoShowToday;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Webhooks\WebhookDispatcher;

/** Öğrenci bugün hiç kuruma girmediyse veliye bildirim: trigger `student.no_show_today`. */
class AutomateOnStudentNoShow
{
    public function handle(StudentNoShowToday $event): void
    {
        $student = Student::query()->withoutGlobalScope('branch')->find($event->studentId);
        if (! $student) {
            return;
        }

        if (! config('kurs.silent_events')) {
            app(WebhookDispatcher::class)->dispatch('student.no_show_today', ['student_id' => $student->id, 'date' => $event->date], $student->branch_id);
        }

        AutomationEngine::fire('student.no_show_today', $student, [], [
            'dedupe_suffix' => "no_show:{$event->date}",
        ]);
    }
}
