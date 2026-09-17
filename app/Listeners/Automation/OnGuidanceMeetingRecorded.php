<?php

namespace App\Listeners\Automation;

use App\Events\GuidanceMeetingRecorded;
use App\Jobs\RecomputeRiskScores;
use App\Models\Student;
use App\Models\Task;

/**
 * Rehberlik görüşmesi kaydedildi → öğrencinin risk puanı kuyrukta yeniden hesaplanır ve risk
 * otomasyonunun açtığı "Görüşme planla" görevi (varsa) tamamlandı sayılır.
 */
class OnGuidanceMeetingRecorded
{
    public const RISK_TASK_PREFIX = 'Görüşme planla: ';

    public function handle(GuidanceMeetingRecorded $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($event->studentId);
        if (! $student) {
            return;
        }

        Task::query()->withoutGlobalScope('branch')->where('taskable_type', 'student')->where('taskable_id', $student->id)
            ->whereNull('completed_at')->where('title', 'like', self::RISK_TASK_PREFIX.'%')
            ->update(['completed_at' => now(), 'updated_at' => now()]);

        RecomputeRiskScores::dispatch([$student->id], (int) $student->branch_id, 'guidance');
    }
}
