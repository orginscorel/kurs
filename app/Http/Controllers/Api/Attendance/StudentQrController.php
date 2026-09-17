<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Student;
use App\Services\Attendance\QrIdentityService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/** Öğrenci QR kimliği: yazdırılabilir kart için değer + yenileme. */
class StudentQrController extends ApiController
{
    public function __construct(private readonly QrIdentityService $qr) {}

    public function show(Student $student): JsonResponse
    {
        return response()->json([
            'student' => [
                'id' => $student->id, 'full_name' => $student->full_name, 'student_no' => $student->student_no,
                'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
            ],
            'value' => $this->qr->forStudent($student),
        ]);
    }

    public function regenerate(Student $student): JsonResponse
    {
        $value = $this->qr->regenerate($student);
        Audit::log('student.qr_regenerated', "{$student->full_name} için QR kimliğini yeniledi (eskisi geçersiz kılındı).", $student);

        return response()->json(['message' => 'Yeni QR kod üretildi; eski kod artık geçersiz.', 'value' => $value]);
    }
}
