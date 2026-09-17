<?php

namespace App\Sync\Local;

use App\Models\Payment;
use App\Services\Finance\CardPaymentDetails;

/** Yerel düğümde kart komisyonu/taksit düzeltmesi de komut olarak eşitlenir. */
class LocalCardPaymentDetails extends CardPaymentDetails
{
    public function override(Payment $p, ?string $rate, ?int $cardInstallments): void
    {
        app(LocalCommandRecorder::class)->run('payment.card_details', [
            'payment' => $p->id, 'rate' => $rate, 'installments' => $cardInstallments,
        ], function () use ($p, $rate, $cardInstallments) {
            parent::override($p, $rate, $cardInstallments);

            return $p;
        });
    }
}
