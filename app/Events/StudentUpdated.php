<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Öğrenci bilgileri güncellendi. $fields: değişen alan adları (değerler KVKK gereği taşınmaz). */
class StudentUpdated
{
    use Dispatchable;

    /** @param list<string> $fields */
    public function __construct(public readonly int $studentId, public readonly array $fields = []) {}
}
