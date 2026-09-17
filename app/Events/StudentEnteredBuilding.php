<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * $isLive=false: çevrimdışı kuyruktan geç senkronlanan olay; veliye bildirim üretmez.
 */
class StudentEnteredBuilding
{
    use Dispatchable;

    public function __construct(public readonly int $attendanceEventId, public readonly bool $isLive = true) {}
}
