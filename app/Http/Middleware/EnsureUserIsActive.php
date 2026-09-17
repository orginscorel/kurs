<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasife alınan kullanıcının açık oturumu bir sonraki istekte kapanır.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Hesabınız pasif durumda. Kurum yöneticisiyle görüşün.',
                'error_code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
