<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * API oturumu: web paneli (httpOnly çerez) için oturum başlatır; oturum çerezi taşımayan Bearer jetonlu
 * istekler (mobil uygulama, masaüstü eşitleme, donanım köprüsü) için başlatmaz → her çağrıda `sessions`
 * tablosuna yeni satır yazılmaz. Çerezle birlikte Bearer gelirse (SPA içinden) normal oturum kullanılır.
 */
class StartSessionUnlessBearer extends StartSession
{
    public function handle($request, Closure $next): Response
    {
        if (self::isStatelessBearer($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    public static function isStatelessBearer(Request $request): bool
    {
        return $request->bearerToken() !== null && ! $request->cookies->has((string) config('session.cookie'));
    }
}
