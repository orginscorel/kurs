<?php

namespace App\Http\Middleware;

use App\Services\Auth\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * İlk girişte şifre belirleme zorunluluğu (öğrenci ve veli portal hesapları). Kurumun verdiği
 * başlangıç şifresiyle (ya da sıfırlanmış şifreyle) oturum açan kullanıcı, şifresini değiştirene
 * kadar yalnız aşağıdaki uçları kullanabilir. Personel önizlemesi (impersonation) bu kilitten
 * muaftır; önizlemede yazma kilidi ayrıca sürer.
 */
class EnsurePasswordChanged
{
    /** Şifre belirlenmeden de erişilebilen uçlar (api/v1 altında). */
    public const ALLOWED = ['auth/me', 'auth/logout', 'auth/change-password', 'client-errors', 'auth/impersonation/leave'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isPortalUser() && $user->requiresPasswordChange()
            && ! Impersonation::activeFor($request)
            && ! in_array(Impersonation::apiPath($request), self::ALLOWED, true)) {
            return response()->json([
                'message' => 'Devam etmek için önce kendi şifrenizi belirleyin.',
                'error_code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
