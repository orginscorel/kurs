<?php

namespace App\Sync\Local;

use App\Models\Payment;
use App\Models\Refund;
use App\Services\Finance\RefundService;

/** Yerel düğümde iade ve iade iptali komut olarak eşitlenir (sunucu RefundService ile yeniden yürütür). */
class LocalRefundService extends RefundService
{
    public function refund(Payment $payment, array $data): Refund
    {
        $data['refunded_at'] = $data['refunded_at'] ?? now()->toDateTimeString();   // iade anı iki tarafta aynı

        return app(LocalCommandRecorder::class)->run('refund.create', [
            'payment' => $payment->id,
            'data' => array_intersect_key($data, array_flip([
                'amount', 'finance_account_id', 'method', 'refunded_at', 'reason', 'payee_name', 'reference', 'idempotency_key',
            ])),
        ], fn () => parent::refund($payment, $data));
    }

    public function void(Refund $refund, string $reason): Refund
    {
        return app(LocalCommandRecorder::class)->run('refund.void', ['refund' => $refund->id, 'reason' => $reason], function () use ($refund, $reason) {
            // İptal taksitlerin ödenen tutarını geri yükler: reddedilirse sunucudan onarılsın
            app(LocalCommandRecorder::class)->touch('installments', $refund->allocations()->pluck('installment_id'));

            return parent::void($refund, $reason);
        });
    }
}
