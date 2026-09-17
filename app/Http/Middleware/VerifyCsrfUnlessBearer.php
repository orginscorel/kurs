<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

/**
 * Çerezle gelen (web paneli) isteklerde CSRF zorunlu. Bearer jetonlu mobil ve
 * donanım köprüsü istekleri çerez taşımadığından CSRF saldırısına açık değildir.
 */
class VerifyCsrfUnlessBearer extends ValidateCsrfToken
{
    public function handle($request, Closure $next)
    {
        if ($this->usesBearerToken($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    private function usesBearerToken(Request $request): bool
    {
        return $request->bearerToken() !== null && ! $request->hasCookie(config('session.cookie'));
    }
}
