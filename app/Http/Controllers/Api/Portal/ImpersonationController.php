<?php

namespace App\Http\Controllers\Api\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\AuthController;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Auth\Impersonation;
use App\Services\Guardians\GuardianAccountService;
use App\Services\Students\StudentAccountService;
use App\Services\Teachers\TeacherAccountService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;

/**
 * "Öğrenci / Veli olarak giriş yap" (önizleme). Yalnız web oturumunda, yalnız öğrenci ya da veli
 * hesabına geçilebilir (personel → personel geçişi yok). Asıl personel id'si sunucu oturumunda saklanır.
 */
class ImpersonationController extends ApiController
{
    public function start(Request $request, Student $student, StudentAccountService $accounts, AuthController $auth): JsonResponse
    {
        $this->assertCanStart($request);

        $target = $accounts->ensure($student)['user'];
        $this->assertTarget($request, $target, User::TYPE_STUDENT);
        if (! $target->is_active) {
            throw new BusinessRuleException('Öğrencinin portal hesabı pasif durumda.', 'student_account_inactive', [], 422);
        }

        Audit::log('student.impersonation_started', "{$student->full_name} ({$student->student_no}) öğrencisi olarak önizlemeye geçti.", $student);

        return $this->switchTo($request, $auth, $target,
            Impersonation::make(Impersonation::KIND_STUDENT, $request->user()->id, $request->user()->name, $student->id, $student->full_name, $target->id));
    }

    public function startGuardian(Request $request, Guardian $guardian, GuardianAccountService $accounts, AuthController $auth): JsonResponse
    {
        $this->assertCanStart($request);

        $result = $accounts->ensure($guardian);
        if (! $result['user']) {
            throw new BusinessRuleException($result['problem'] === GuardianAccountService::PROBLEM_PHONE_CONFLICT
                ? 'Velinin telefon numarası başka bir hesapta kullanılıyor; portal hesabı açılamadı.'
                : 'Velinin geçerli bir cep telefonu olmadığı için portal hesabı yok.', 'guardian_account_missing', [], 422);
        }
        $target = $result['user'];
        $this->assertTarget($request, $target, User::TYPE_GUARDIAN);
        if (! $target->is_active) {
            throw new BusinessRuleException('Velinin portal hesabı pasif durumda (açık öğrencisi yok).', 'guardian_account_inactive', [], 422);
        }

        Audit::log('guardian.impersonation_started', "{$guardian->full_name} velisi olarak önizlemeye geçti.", $guardian);

        return $this->switchTo($request, $auth, $target,
            Impersonation::make(Impersonation::KIND_GUARDIAN, $request->user()->id, $request->user()->name, $guardian->id, $guardian->full_name, $target->id));
    }

    /**
     * Öğretmen portalı önizlemesi. Yalnız YALNIZ-portal öğretmen hesabına geçilir (ek yönetim rolü olan
     * öğretmene geçmek önizleyen personele o rolün okuma yetkisini verirdi).
     */
    public function startTeacher(Request $request, Teacher $teacher, TeacherAccountService $accounts, AuthController $auth): JsonResponse
    {
        $this->assertCanStart($request);

        $target = $accounts->ensure($teacher)['user'];
        $this->assertTarget($request, $target, User::TYPE_TEACHER);
        if (! $target->isTeacherPortalUser()) {
            throw new BusinessRuleException('Bu öğretmenin hesabında yönetim rolü de var; önizleme yalnız öğretmen portalı hesaplarında yapılabilir.', 'impersonation_target_invalid', [], 403);
        }
        if (! $target->is_active || ! $teacher->is_active) {
            throw new BusinessRuleException('Öğretmenin portal hesabı pasif durumda.', 'teacher_account_inactive', [], 422);
        }

        Audit::log('teacher.impersonation_started', "{$teacher->full_name} öğretmeni olarak önizlemeye geçti.", $teacher);

        return $this->switchTo($request, $auth, $target,
            Impersonation::make(Impersonation::KIND_TEACHER, $request->user()->id, $request->user()->name, $teacher->id, $teacher->full_name, $target->id));
    }

    public function leave(Request $request, AuthController $auth): JsonResponse
    {
        $data = Impersonation::activeFor($request);

        if (! $data) {
            throw new BusinessRuleException('Aktif bir önizleme oturumu yok.', 'impersonation_missing', [], 409);
        }

        $staff = User::query()->find($data['impersonator_id']);

        if (! $staff || ! $staff->is_active || ! $staff->isStaff()) {
            // Asıl hesap artık geçerli değil: güvenli taraf — oturumu tamamen kapat.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new BusinessRuleException('Yönetici oturumu geri yüklenemedi. Lütfen tekrar giriş yapın.', 'impersonation_restore_failed', [], 401);
        }

        Auth::guard('web')->login($staff);
        // İstek içindeki varsayılan (sanctum) koruyucu da asıl personeli görsün: denetim kaydı ona yazılır
        Auth::setUser($staff);
        $request->setUserResolver(fn () => $staff);
        $request->session()->forget(Impersonation::SESSION_KEY);
        $request->session()->regenerate();

        $minutes = max(0, (int) round((now()->getTimestamp() - strtotime($data['started_at'])) / 60));
        $name = Impersonation::targetName($data);
        if (Impersonation::kind($data) === Impersonation::KIND_GUARDIAN) {
            $subject = Guardian::query()->find(Impersonation::targetId($data));
            Audit::log('guardian.impersonation_ended', "{$name} velisi önizlemesini bitirdi ({$minutes} dk).", $subject);
        } elseif (Impersonation::kind($data) === Impersonation::KIND_TEACHER) {
            $subject = Teacher::query()->find(Impersonation::targetId($data));
            Audit::log('teacher.impersonation_ended', "{$name} öğretmeni önizlemesini bitirdi ({$minutes} dk).", $subject);
        } else {
            $subject = Student::query()->find(Impersonation::targetId($data));
            Audit::log('student.impersonation_ended', "{$name} öğrencisi önizlemesini bitirdi ({$minutes} dk).", $subject);
        }

        return response()->json($auth->mePayload($staff) + ['impersonation' => null, 'return_to' => Impersonation::returnPath($data)]);
    }

    private function assertCanStart(Request $request): void
    {
        $staff = $request->user();
        $token = $staff->currentAccessToken();
        if (! $request->hasSession() || ($token && ! $token instanceof TransientToken)) {
            throw new BusinessRuleException('Önizleme yalnız web panelinden başlatılabilir.', 'impersonation_web_only', [], 400);
        }
        if (Impersonation::current($request)) {
            throw new BusinessRuleException('Zaten bir önizleme oturumundasınız. Önce yönetime dönün.', 'impersonation_active', [], 409);
        }
        if (! $staff->isStaff()) {
            throw new BusinessRuleException('Bu işlem için yetkiniz yok.', 'forbidden', [], 403);
        }
    }

    /** Yalnız beklenen türde portal hesabı: süper yönetici dahil kimse personel hesabına geçemez. */
    private function assertTarget(Request $request, User $target, string $type): void
    {
        if ($target->user_type !== $type || $target->trashed() || $target->hasRole('super-admin') || $target->id === $request->user()->id) {
            throw new BusinessRuleException('Yalnız öğrenci, veli ya da öğretmen portalı hesabıyla önizleme yapılabilir.', 'impersonation_target_invalid', [], 403);
        }
    }

    private function switchTo(Request $request, AuthController $auth, User $target, array $data): JsonResponse
    {
        Auth::guard('web')->login($target);
        Auth::setUser($target);
        $request->setUserResolver(fn () => $target);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, $data);

        return response()->json($auth->mePayload($target) + ['impersonation' => Impersonation::publicPayload($data)]);
    }
}
