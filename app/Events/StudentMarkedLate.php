<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Öğrenci derse geç kaldı (otomatik yoklama ya da öğretmen/yönetici elle yoklaması). Trigger `attendance.late`. */
class StudentMarkedLate
{
    use Dispatchable;

    public function __construct(public readonly int $attendanceId) {}
}
