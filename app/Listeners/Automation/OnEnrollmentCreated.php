<?php

namespace App\Listeners\Automation;

use App\Events\EnrollmentCreated;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\StaffRecipients;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Money;

/**
 * Yeni kayıt → webhook `enrollment.created`, veliye hoş geldin (trigger `enrollment.welcome`,
 * şablon `enrollment.welcome`), muhasebeye "yeni ödeme planı" uygulama bildirimi.
 */
class OnEnrollmentCreated
{
    public function handle(EnrollmentCreated $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $enrollment = Enrollment::query()->withoutGlobalScope('branch')->with(['program:id,name', 'installments'])->find($event->enrollmentId);
        if (! $enrollment) {
            return;
        }
        $student = Student::query()->withoutGlobalScope('branch')->find($enrollment->student_id);
        if (! $student) {
            return;
        }

        $installments = $enrollment->installments->sortBy('due_date')->values();
        $net = Money::format((string) $enrollment->net_price);
        $firstDue = $installments->first()?->due_date;
        $firstDueLabel = $firstDue ? \Carbon\Carbon::parse($firstDue)->format('d.m.Y') : '—';

        app(WebhookDispatcher::class)->dispatch('enrollment.created', [
            'enrollment_id' => $enrollment->id, 'enrollment_no' => $enrollment->enrollment_no, 'student_id' => $student->id,
            'program_id' => $enrollment->program_id, 'net_price' => (string) $enrollment->net_price, 'installment_count' => $installments->count(),
        ], $enrollment->branch_id);

        AutomationEngine::fire('enrollment.welcome', $student, [
            'program_adi' => $enrollment->program?->name ?? '',
            'kayit_no' => (string) $enrollment->enrollment_no,
            'taksit_sayisi' => (string) $installments->count(),
            'net_tutar' => $net,
            'ilk_vade' => $firstDueLabel,
        ], [
            'program_id' => $enrollment->program_id,
            'class_group_id' => $enrollment->class_group_id,
            'dedupe_suffix' => "enrollment:{$enrollment->id}",
        ]);

        if ($installments->isNotEmpty()) {
            app(NotificationService::class)->notify(
                StaffRecipients::withPermission($enrollment->branch_id, 'payments.create'),
                'payment',
                "Yeni ödeme planı: {$student->full_name}",
                sprintf('%s · %s · %d taksit, toplam %s TL, ilk vade %s.', $enrollment->enrollment_no, $enrollment->program?->name ?? 'Program', $installments->count(), $net, $firstDueLabel),
                "/finans/kayitlar/{$enrollment->id}",
                ['kind' => 'enrollment_created', 'enrollment_id' => $enrollment->id, 'once' => "enrollment_created:{$enrollment->id}"],
            );
        }
    }
}
