<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Kayıt + ödeme planı oluşturuldu (EnrollmentService::enroll). Trigger `enrollment.welcome`. */
class EnrollmentCreated
{
    use Dispatchable;

    public function __construct(public readonly int $enrollmentId) {}
}
