<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Öğrencinin ilk dersinden X dakika sonra hâlâ kuruma girişi yok. Otomasyon motoru
 * (iletişim modülü) bunu dinleyip veliye bildirim gönderecek; bu olay yalnız
 * gün içinde öğrenci başına BİR kez tetiklenir (routes/schedules/attendance.php).
 */
class StudentNoShowToday
{
    use Dispatchable;

    public function __construct(public readonly int $studentId, public readonly string $date) {}
}
