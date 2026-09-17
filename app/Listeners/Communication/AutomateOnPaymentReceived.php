<?php

namespace App\Listeners\Communication;

use App\Events\PaymentReceived;
use App\Models\Payment;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat alındığında veliye makbuz bilgisi + webhook: trigger `payment.received`.
 * Varsayılan kural (şablon `payment.receipt`) veri migration'ı ile PASİF eklenir; yönetici açınca etkinleşir.
 * Değişkenler: tutar, tarih, makbuz_no, odeme_yontemi, kalan_bakiye.
 */
class AutomateOnPaymentReceived
{
    public function handle(PaymentReceived $event): void
    {
        $payment = Payment::query()->withoutGlobalScope('branch')->find($event->paymentId);
        if (! $payment || $payment->voided_at || ! $payment->student_id) {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($payment->student_id);
        if (! $student) {
            return;
        }

        if (! config('kurs.silent_events')) {
            app(WebhookDispatcher::class)->dispatch('payment.received', [
                'payment_id' => $payment->id, 'student_id' => $student->id, 'amount' => (string) $payment->amount,
                'receipt_no' => $payment->receipt_no, 'method' => $payment->method, 'paid_at' => $payment->paid_at?->toIso8601String(),
            ], $payment->branch_id);
        }

        // Salt okunur: öğrencinin açık taksit bakiyesi (bcmath, float yok).
        $remaining = (string) (DB::table('installments')->where('student_id', $student->id)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) AS r')->value('r') ?? '0');

        AutomationEngine::fire('payment.received', $student, [
            'tutar' => Money::format((string) $payment->amount),
            'tarih' => $payment->paid_at?->timezone('Europe/Istanbul')->format('d.m.Y') ?? '',
            'makbuz_no' => (string) $payment->receipt_no,
            'odeme_yontemi' => Payment::METHODS[$payment->method] ?? (string) $payment->method,
            'kalan_bakiye' => Money::format($remaining),
        ], [
            'dedupe_suffix' => "payment:{$payment->id}",
        ]);
    }
}
