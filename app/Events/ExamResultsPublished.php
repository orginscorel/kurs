<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ExamResultsPublished
{
    use Dispatchable;

    public function __construct(public readonly int $examId) {}
}
