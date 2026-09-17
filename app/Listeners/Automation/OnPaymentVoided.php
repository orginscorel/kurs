<?php

namespace App\Listeners\Automation;

use App\Events\PaymentVoided;
use App\Models\ActivityFeed;
use App\Models\Payment;
use App\Models\Student;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\StaffRecipients;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Money;

/** Tahsilat iptali → tahsilat yetkili yöneticilere uygulama bildirimi + webhook `payment.voided` + canlı akış. */
class OnPaymentVoided
{
    public function handle(PaymentVoided $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $payment = Payment::query()->withoutGlobalScope('branch')->find($event->paymentId);
        if (! $payment || ! $payment->voided_at) {
            return;
        }

        $student = $payment->student_id ? Student::query()->withoutGlobalScope('branch')->find($payment->student_id) : null;
        $amount = Money::format((string) $payment->amount);

        app(WebhookDispatcher::class)->dispatch('payment.voided', [
            'payment_id' => $payment->id, 'student_id' => $payment->student_id, 'receipt_no' => $payment->receipt_no,
            'amount' => (string) $payment->amount, 'voided_at' => $payment->voided_at->toIso8601String(),
        ], $payment->branch_id);

        ActivityFeed::query()->withoutGlobalScope('branch')->create([
            'branch_id' => $payment->branch_id, 'kind' => 'payment',
            'message' => sprintf('%s makbuzlu %s TL tahsilat iptal edildi%s', $payment->receipt_no, $amount, $student ? " ({$student->full_name})" : ''),
            'subject_type' => $payment->getMorphClass(), 'subject_id' => $payment->id, 'student_id' => $payment->student_id,
            'meta' => ['voided' => true], 'occurred_at' => now(),
        ]);

        app(NotificationService::class)->notify(
            StaffRecipients::withPermission($payment->branch_id, 'payments.create'),
            'payment',
            'Tahsilat iptal edildi',
            sprintf('%s · %s TL%s. Gerekçe: %s', $payment->receipt_no, $amount, $student ? " · {$student->full_name}" : '', $payment->void_reason ?? '—'),
            $student ? "/ogrenciler/{$student->id}" : '/finans/tahsilatlar',
            ['kind' => 'payment_voided', 'payment_id' => $payment->id, 'once' => "payment_voided:{$payment->id}"],
        );
    }
}
