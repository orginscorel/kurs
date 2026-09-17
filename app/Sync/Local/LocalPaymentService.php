<?php

namespace App\Sync\Local;

use App\Models\Payment;
use App\Models\Student;
use App\Services\Finance\PaymentService;

/**
 * Yerel düğümde PaymentService yerine bağlanır (SyncServiceProvider). İş mantığı aynen üst sınıfta;
 * yalnız çağrı eşitleme komutu olarak kaydedilir.
 */
class LocalPaymentService extends PaymentService
{
    public function collect(Student $student, array $data): Payment
    {
        // Tahsilat anı iki tarafta aynı olsun
        $data['paid_at'] = $data['paid_at'] ?? now()->toDateTimeString();

        return app(LocalCommandRecorder::class)->run('payment.collect', [
            'student' => $student->id,
            'data' => array_intersect_key($data, array_flip([
                'finance_account_id', 'method', 'amount', 'paid_at', 'enrollment_id', 'guardian_id', 'installment_ids',
                'reference', 'note', 'payer_name', 'idempotency_key', 'overpayment', 'commission_rate', 'card_installments',
            ])),
        ], fn () => parent::collect($student, $data));
    }

    public function void(Payment $payment, string $reason): Payment
    {
        return app(LocalCommandRecorder::class)->run('payment.void', [
            'payment' => $payment->id,
            'reason' => $reason,
        ], fn () => parent::void($payment, $reason));
    }
}
