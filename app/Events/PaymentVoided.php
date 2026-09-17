<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Tahsilat iptal edildi (PaymentService::void, transaction commit sonrası). */
class PaymentVoided
{
    use Dispatchable;

    public function __construct(public readonly int $paymentId) {}
}
