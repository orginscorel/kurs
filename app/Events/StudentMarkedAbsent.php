<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class StudentMarkedAbsent
{
    use Dispatchable;

    public function __construct(public readonly int $attendanceId) {}
}
