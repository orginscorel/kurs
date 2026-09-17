<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Rehberlik görüşmesi kaydedildi/güncellendi → öğrencinin risk puanı yeniden hesaplanır. */
class GuidanceMeetingRecorded
{
    use Dispatchable;

    public function __construct(public readonly int $meetingId, public readonly int $studentId) {}
}
