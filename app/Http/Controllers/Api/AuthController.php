<?php

namespace App\Http\Controllers\Api;

use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Auth\Impersonation;
use App\Services\Auth\SessionDirectory;
use App\Services\Guardians\GuardianAccountService;
use App\Support\Audit;
use App\Support\Permissions;
use App\Support\Settings;
use App\Sync\Local\LocalPasswordProxy;
use App\Sync\Local\LocalSessionDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class AuthController extends ApiController
{
    /** Web paneli: httpOnly oturum çereziyle giriş. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        if (! $request->hasSession()) {
            // Çerezsiz Bearer isteğinde oturum başlatılmaz (StartSessionUnlessBearer): mobil istemci auth/token kullanır
            return response()->json(['message' => 'Bu giriş web paneli içindir; uygulamalar jetonla giriş yapar (auth/token).', 'error_code' => 'session_required'], 400);
        }

        $user = $this->attempt($data['login'], $data['password'], 'web', $request);

        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->forget(Impersonation::SESSION_KEY);
        $request->session()->regenerate();

        return response()->json($this->mePayload($user));
    }

    /** Mobil uygulama: kişisel erişim jetonu (cihaz adıyla, cihaz yönetiminde listelenir). */
    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = $this->attempt($data['login'], $data['password'], 'mobile', $request);
        $token = $user->createToken(mb_substr($data['device_name'], 0, 120), ['*'], now()->addDays(60));

        return response()->json(['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at] + $this->mePayload($user));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->mePayload($request->user()) + [
            'impersonation' => Impersonation::publicPayload(Impersonation::activeFor($request)),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token && ! $token instanceof TransientToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->ok('Çıkış yapıldı.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        // Öğrenci/veli hesapları için daha kısa (en az 8) ama yine harf+rakam içeren parola
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min($user->isPortalUser() ? 8 : 10)->letters()->numbers()],
        ], [
            'password.different' => 'Yeni parola mevcut paroladan farklı olmalı.',
            'current_password.required' => 'Mevcut parolanızı girin.',
            'password.required' => 'Yeni parolayı girin.',
            'password.confirmed' => 'Yeni parola ile tekrarı aynı değil.',
            'password.min' => 'Yeni parola en az :min karakter olmalı.',
            'password.letters' => 'Yeni parola en az bir harf içermeli.',
            'password.numbers' => 'Yeni parola en az bir rakam içermeli.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mevcut parola hatalı.']);
        }

        $currentSession = $request->hasSession() ? $request->session()->getId() : null;
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken ? $currentToken->getKey() : null;

        if (config('kurs.node') === 'local') {
            // Yerel kurulum: parola sunucu-otoriteli; yalnız çevrimiçi, sunucuda doğrulanarak değişir (docs/SYNC.md)
            app(LocalPasswordProxy::class)->change($user, $data['current_password'], $data['password']);
            DB::table('sessions')->where('user_id', $user->id)->when($currentSession, fn ($q) => $q->where('id', '!=', $currentSession))->delete();

            return $this->ok('Parolanız sunucuda güncellendi. Diğer cihazlar bir sonraki eşitlemede yeni parolayı alır.');
        }

        // Okunabilir başlangıç şifresi artık geçersiz: personel panelinde "şifresini değiştirdi" görünür
        $user->forceFill([
            'password' => $data['password'], 'must_change_password' => false,
            'initial_password' => null, 'password_changed_at' => now(),
        ])->save();

        // Diğer tüm oturum ve jetonları kapat; mevcut oturum (çerez ya da Bearer jeton) açık kalır.
        DB::table('sessions')->where('user_id', $user->id)->when($currentSession, fn ($q) => $q->where('id', '!=', $currentSession))->delete();
        $user->tokens()->when($currentTokenId, fn ($q) => $q->whereKeyNot($currentTokenId))->delete();
        Audit::log('auth.password_changed', 'parolasını değiştirdi.', $user);

        return $this->ok('Parolanız güncellendi. Diğer cihazlardaki oturumlar kapatıldı.');
    }

    /**
     * Açık oturumlar ve cihazlar — TEK LİSTE: tarayıcı (web:), Mac uygulaması (app:), mobil jeton (token:).
     * Yerel kurulumda (Mac) yerel oturumlar + çevrimiçiyse sunucudaki tam liste (LocalSessionDirectory).
     */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (config('kurs.node') === 'local') {
            return response()->json(app(LocalSessionDirectory::class)->list($user, $request));
        }

        $logins = LoginEvent::query()->where('user_id', $user->id)->latest('created_at')->limit(15)->get();

        return response()->json([
            'data' => app(SessionDirectory::class)->forUser($user, SessionDirectory::currentOf($request)),
            'recent_logins' => $logins,
            'node' => 'server',
            'scope' => 'all',
            'notice' => null,
        ]);
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (config('kurs.node') === 'local') {
            return $this->ok(app(LocalSessionDirectory::class)->revoke($user, $id, $request));
        }

        return $this->ok(app(SessionDirectory::class)->revoke($user, $id, $user, SessionDirectory::currentOf($request)));
    }

    /** "Diğer tüm oturumları kapat": web + uygulama + mobil (mevcut oturum hariç). */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (config('kurs.node') === 'local') {
            return response()->json(app(LocalSessionDirectory::class)->revokeOthers($user, $request));
        }

        return response()->json(app(SessionDirectory::class)->revokeOthers($user, $user, SessionDirectory::currentOf($request)));
    }

    private function attempt(string $login, string $password, string $channel, Request $request): User
    {
        // Veli kullanıcı adı cep telefonudur (05xxxxxxxxx); "0532 123 45 67", "+90 532…" gibi yazımlar da kabul edilir.
        $phoneLogin = preg_match('/^[\d\s()+\-.]{10,20}$/', $login) ? GuardianAccountService::usernameFor($login) : null;
        $user = User::query()
            ->where(fn ($q) => $q->where('username', $login)->orWhere('email', $login)
                ->when($phoneLogin && $phoneLogin !== $login, fn ($w) => $w->orWhere(fn ($g) => $g->where('username', $phoneLogin)->where('user_type', User::TYPE_GUARDIAN))))
            ->orderByRaw('username = ? DESC', [$login])
            ->first();

        // Kullanıcı yoksa da hash karşılaştırması yap: yanıt süresi kullanıcı varlığını sızdırmasın.
        $valid = Hash::check($password, $user?->password ?? '$2y$12$'.str_repeat('a', 53));

        LoginEvent::query()->create([
            'user_id' => $user?->id,
            'username' => mb_substr($login, 0, 120),
            'successful' => $valid && $user?->is_active,
            'channel' => $channel,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        if (! $user || ! $valid) {
            throw ValidationException::withMessages(['login' => 'Kullanıcı adı veya parola hatalı.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'Hesabınız pasif durumda. Kurum yöneticisiyle görüşün.']);
        }

        // Yerel kurulumda (masaüstü) parola özeti sunucu-otoriteldir: yeniden özetleme users satırını
        // kirletir ve ChangeRecorder girişi 409 ile düşürür → çevrimdışı hiç giriş yapılamazdı.
        if (config('kurs.node') !== 'local' && Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password]);
        }
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return $user;
    }

    /** Oturum açan kullanıcı + yetkiler + portal + marka. İstemci menüsünü buna göre kurar. */
    public function mePayload(User $user): array
    {
        $user->loadMissing(['branch', 'teacher:id,user_id', 'student:id,user_id', 'guardian:id,user_id']);
        $isSuper = $user->hasRole('super-admin');
        $institution = Settings::group('institution', $user->branch_id);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'user_type' => $user->user_type,
                'avatar_url' => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
                // Portal hesabında başlangıç/sıfırlanmış şifre → ön yüz "Şifrenizi belirleyin" adımını gösterir
                'must_change_password' => $user->requiresPasswordChange(),
                'teacher_id' => $user->teacher?->id,
                'student_id' => $user->student?->id,
                'guardian_id' => $user->guardian?->id,
            ],
            'branch' => $user->branch ? ['id' => $user->branch->id, 'name' => $user->branch->name, 'code' => $user->branch->code] : null,
            // Kullanıcının kabuğu: student | guardian | teacher (yalnız öğretmen portalı) | null (yönetim)
            'portal' => $user->portalKind(),
            'roles' => $user->getRoleNames()->values(),
            'is_super_admin' => $isSuper,
            'permissions' => $isSuper ? Permissions::all() : $user->getAllPermissions()->pluck('name')->values(),
            'institution' => [
                'name' => $institution['name'],
                'short_name' => $institution['short_name'],
                'logo_url' => $institution['logo_path'] ? Storage::disk('public')->url($institution['logo_path']) : null,
                'onboarding_completed' => (bool) $institution['onboarding_completed'],
            ],
        ];
    }
}
