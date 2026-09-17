<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Öğrenci kaydı oluşturuldu (form ya da CRM adayından dönüştürme). Webhook `student.created`. */
class StudentCreated
{
    use Dispatchable;

    public function __construct(public readonly int $studentId) {}
}
