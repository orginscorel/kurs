<?php

namespace App\Sync\Local;

use App\Models\Enrollment;
use App\Models\Student;
use App\Services\Finance\EnrollmentService;

/** Yerel düğümde EnrollmentService yerine bağlanır; kayıt + ödeme planı komut olarak eşitlenir. */
class LocalEnrollmentService extends EnrollmentService
{
    public function enroll(Student $student, array $data): Enrollment
    {
        return app(LocalCommandRecorder::class)->run('enrollment.create', [
            'student' => $student->id,
            'data' => $data,
        ], fn () => parent::enroll($student, $data));
    }
}
