<?php

namespace App\Http\Controllers\Api\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Guardian;
use App\Services\Guardians\GuardianAccountService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personel tarafı: velinin portal hesabı (kullanıcı adı = cep telefonu, başlangıç şifresi, sıfırlama).
 * Şifre yalnız guardians.credentials yetkisiyle ve denetim kaydına yazılarak gösterilir.
 */
class GuardianAccountController extends ApiController
{
    public function __construct(private readonly GuardianAccountService $accounts) {}

    public function show(Request $request, Guardian $guardian): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->accounts->summary($guardian) + [
            'can_view_credentials' => $user->can('guardians.credentials'),
            'can_impersonate' => $user->can('guardians.impersonate'),
        ]);
    }

    /** Hesabı yoksa açar (idempotent). Telefon eksik/çakışıyorsa açıklayıcı hata. */
    public function store(Guardian $guardian): JsonResponse
    {
        $result = $this->accounts->ensure($guardian);

        if (! $result['user']) {
            $this->throwProblem($result['problem'], $result['conflict']);
        }
        if ($result['created']) {
            $this->accounts->syncActive($guardian->refresh(), audit: false);
        }

        return response()->json([
            'message' => $result['created'] ? 'Veli portal hesabı açıldı.' : 'Velinin portal hesabı zaten var.',
        ] + $this->accounts->summary($guardian->refresh()));
    }

    /** Kullanıcı adı + (değiştirilmediyse) başlangıç şifresi. */
    public function reveal(Guardian $guardian): JsonResponse
    {
        $user = $this->accounts->user($guardian);

        if (! $user || $user->trashed()) {
            throw new BusinessRuleException('Velinin portal hesabı henüz açılmamış.', 'guardian_account_missing', [], 404);
        }

        $password = $user->initial_password;
        Audit::log('guardian.credentials_viewed', "{$guardian->full_name} velisinin portal giriş bilgilerini görüntüledi.", $guardian);

        return response()->json([
            'username' => $user->username,
            'password' => $password,
            'password_changed' => $password === null,
        ]);
    }

    public function resetPassword(Guardian $guardian): JsonResponse
    {
        $password = $this->accounts->resetPassword($guardian);

        return response()->json([
            'message' => 'Yeni başlangıç şifresi oluşturuldu. Velinin açık oturumları kapatıldı.',
            'username' => $this->accounts->user($guardian)?->username,
            'password' => $password,
        ]);
    }

    private function throwProblem(?string $problem, ?array $conflict): never
    {
        if ($problem === GuardianAccountService::PROBLEM_PHONE_CONFLICT) {
            throw new BusinessRuleException(
                $conflict['guardian_name'] ?? null
                    ? "Bu cep telefonu {$conflict['guardian_name']} velisinin hesabında kullanılıyor. Aynı kişiyse kayıtları birleştirin; değilse telefonu düzeltin."
                    : 'Bu cep telefonu başka bir kullanıcı hesabında kullanılıyor. Telefonu kontrol edin.',
                'guardian_phone_conflict', ['guardian_id' => $conflict['guardian_id'] ?? null], 422);
        }

        throw new BusinessRuleException('Portal hesabı için velinin geçerli bir cep telefonu (05xx…) girilmeli.', 'guardian_phone_missing', [], 422);
    }
}
