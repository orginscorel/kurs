<?php

namespace App\Listeners\Automation;

use App\Events\LeadConverted;
use App\Models\Lead;
use App\Models\Student;
use App\Services\Notifications\NotificationService;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Auth;

/** CRM adayı öğrenciye dönüştü → webhook `lead.converted` + (dönüştüren başkasıysa) aday sorumlusuna bildirim. */
class OnLeadConverted
{
    public function handle(LeadConverted $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $lead = Lead::query()->withoutGlobalScope('branch')->find($event->leadId);
        $student = Student::query()->withoutGlobalScope('branch')->find($event->studentId);
        if (! $lead || ! $student) {
            return;
        }

        app(WebhookDispatcher::class)->dispatch('lead.converted', [
            'lead_id' => $lead->id, 'student_id' => $student->id, 'student_no' => $student->student_no, 'source' => $lead->source,
        ], $lead->branch_id);

        if ($lead->owner_id && (int) $lead->owner_id !== (int) Auth::id()) {
            app(NotificationService::class)->notify((int) $lead->owner_id, 'success', 'Adayınız kayıt oldu',
                "{$lead->full_name} öğrenci olarak kaydedildi ({$student->student_no}).", "/ogrenciler/{$student->id}",
                ['kind' => 'lead_converted', 'lead_id' => $lead->id, 'once' => "lead_converted:{$lead->id}"]);
        }
    }
}
