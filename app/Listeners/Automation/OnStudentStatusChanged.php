<?php

namespace App\Listeners\Automation;

use App\Events\StudentStatusChanged;
use App\Models\AutomationRun;
use App\Models\OutboundMessage;
use App\Models\Student;
use App\Models\Task;
use App\Services\Automation\StudentActivityGate;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Öğrenci durumu değişti → webhook `student.updated`. Ayrıldı/Donduruldu/Mezun olduysa:
 *  - planlanmış (gecikmeli) otomasyon çalışmaları `skipped`,
 *  - henüz gönderilmemiş kuyruktaki mesajları `cancelled`,
 *  - öğrenciye bağlı açık görevler not düşülerek kapatılır.
 * Taksit durumlarına DOKUNULMAZ; hatırlatmalar motorun öğrenci-durumu kapısında durur (StudentActivityGate).
 */
class OnStudentStatusChanged
{
    public function handle(StudentStatusChanged $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($event->studentId);
        if (! $student) {
            return;
        }

        app(WebhookDispatcher::class)->dispatch('student.updated', [
            'student_id' => $student->id, 'student_no' => $student->student_no, 'changed_fields' => ['status'],
            'status_from' => $event->from, 'status_to' => $event->to,
        ], $student->branch_id);

        if (! StudentActivityGate::isInactive($event->to)) {
            return;
        }

        $label = Student::STATUSES[$event->to] ?? $event->to;
        $reason = "Öğrenci durumu \"{$label}\" olduğu için iptal edildi.";

        DB::transaction(function () use ($student, $reason, $label) {
            AutomationRun::query()->where('subject_type', 'student')->where('subject_id', $student->id)->where('status', 'scheduled')
                ->update(['status' => 'skipped', 'result' => $reason, 'updated_at' => now()]);

            OutboundMessage::query()->withoutGlobalScope('branch')->where('student_id', $student->id)->where('status', 'queued')
                ->where(fn ($q) => $q->where('trigger', 'like', 'automation:%')->orWhereNotNull('scheduled_at'))
                ->update(['status' => 'cancelled', 'error' => $reason, 'updated_at' => now()]);

            Task::query()->withoutGlobalScope('branch')->where('taskable_type', 'student')->where('taskable_id', $student->id)
                ->whereNull('completed_at')->get()
                ->each(fn (Task $t) => $t->forceFill([
                    'completed_at' => now(),
                    'description' => mb_substr(trim(($t->description ?? '')."\n\n[Otomatik kapatıldı: öğrenci durumu {$label}]"), 0, 5000),
                ])->save());
        });
    }
}
