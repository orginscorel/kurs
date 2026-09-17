<?php

namespace App\Http\Controllers\Api\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentAccountService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personel tarafı: öğrencinin portal hesabı (kullanıcı adı, başlangıç şifresi, sıfırlama).
 * Şifre yalnız students.credentials yetkisiyle ve denetim kaydına yazılarak gösterilir.
 */
class StudentAccountController extends ApiController
{
    public function __construct(private readonly StudentAccountService $accounts) {}

    public function show(Request $request, Student $student): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->accounts->summary($student) + [
            'student_no' => $student->student_no,
            'can_view_credentials' => $user->can('students.credentials'),
            'can_impersonate' => $user->can('students.impersonate'),
        ]);
    }

    /** Hesabı yoksa açar (idempotent). */
    public function store(Student $student): JsonResponse
    {
        $result = $this->accounts->ensure($student);

        return response()->json([
            'message' => $result['created'] ? 'Öğrenci portal hesabı açıldı.' : 'Öğrencinin portal hesabı zaten var.',
        ] + $this->accounts->summary($student->refresh()));
    }

    /** Kullanıcı adı + (değiştirilmediyse) başlangıç şifresi. */
    public function reveal(Student $student): JsonResponse
    {
        $user = $student->user_id ? User::query()->find($student->user_id) : null;

        if (! $user) {
            throw new BusinessRuleException('Öğrencinin portal hesabı henüz açılmamış.', 'student_account_missing', [], 404);
        }

        $password = $user->initial_password;
        Audit::log('student.credentials_viewed', "{$student->full_name} ({$student->student_no}) öğrencisinin portal giriş bilgilerini görüntüledi.", $student);

        return response()->json([
            'username' => $user->username,
            'password' => $password,
            'password_changed' => $password === null,
        ]);
    }

    public function resetPassword(Student $student): JsonResponse
    {
        $password = $this->accounts->resetPassword($student);

        return response()->json([
            'message' => 'Yeni başlangıç şifresi oluşturuldu. Öğrencinin açık oturumları kapatıldı.',
            'username' => $student->refresh()->user?->username,
            'password' => $password,
        ]);
    }
}
