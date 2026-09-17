<?php

namespace App\Sync\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cihaz eşitleme jetonu (yalnız 'sync' yeteneği) diğer yönetim uçlarında kullanılamaz:
 * çalınan bir cihaz jetonu tam API erişimi vermez.
 */
class RestrictSyncTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() && ! $request->is('api/v1/sync/*')) {
            $token = Auth::guard('sanctum')->user()?->currentAccessToken();
            if ($token instanceof PersonalAccessToken && (array) $token->abilities === ['sync']) {
                return response()->json(['message' => 'Cihaz eşitleme jetonu bu uçta kullanılamaz.', 'error_code' => 'forbidden'], 403);
            }
        }

        return $next($request);
    }
}
