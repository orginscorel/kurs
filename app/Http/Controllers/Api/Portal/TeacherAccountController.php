<?php

namespace App\Http\Controllers\Api\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Teacher;
use App\Services\Teachers\TeacherAccountService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personel tarafı: öğretmenin portal hesabı (kullanıcı adı, başlangıç şifresi, sıfırlama).
 * Şifre yalnız teachers.credentials yetkisiyle ve denetim kaydına yazılarak gösterilir.
 */
class TeacherAccountController extends ApiController
{
    public function __construct(private readonly TeacherAccountService $accounts) {}

    public function show(Request $request, Teacher $teacher): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->accounts->summary($teacher) + [
            'can_view_credentials' => $user->can('teachers.credentials'),
            'can_impersonate' => $user->can('teachers.impersonate'),
        ]);
    }

    public function store(Teacher $teacher): JsonResponse
    {
        $result = $this->accounts->ensure($teacher);

        return response()->json([
            'message' => $result['created'] ? 'Öğretmen portal hesabı açıldı.' : 'Öğretmenin portal hesabı zaten var.',
        ] + $this->accounts->summary($teacher->refresh()));
    }

    public function reveal(Teacher $teacher): JsonResponse
    {
        $user = $this->accounts->user($teacher);
        if (! $user || $user->trashed()) {
            throw new BusinessRuleException('Öğretmenin portal hesabı henüz açılmamış.', 'teacher_account_missing', [], 404);
        }

        $password = $user->initial_password;
        Audit::log('teacher.credentials_viewed', "{$teacher->full_name} öğretmeninin portal giriş bilgilerini görüntüledi.", $teacher);

        return response()->json([
            'username' => $user->username,
            'password' => $password,
            'password_changed' => $password === null,
        ]);
    }

    public function resetPassword(Teacher $teacher): JsonResponse
    {
        $password = $this->accounts->resetPassword($teacher);

        return response()->json([
            'message' => 'Yeni başlangıç şifresi oluşturuldu. Öğretmenin açık oturumları kapatıldı.',
            'username' => $this->accounts->user($teacher)?->username,
            'password' => $password,
        ]);
    }
}
