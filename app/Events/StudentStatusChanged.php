<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Öğrenci durumu değişti (ör. Aktif → Ayrıldı / Donduruldu). */
class StudentStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly int $studentId, public readonly string $from, public readonly string $to) {}
}
