<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** CRM adayı öğrenciye dönüştürüldü. */
class LeadConverted
{
    use Dispatchable;

    public function __construct(public readonly int $leadId, public readonly int $studentId) {}
}
