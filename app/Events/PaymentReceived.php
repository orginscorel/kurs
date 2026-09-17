<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PaymentReceived
{
    use Dispatchable;

    public function __construct(public readonly int $paymentId) {}
}
