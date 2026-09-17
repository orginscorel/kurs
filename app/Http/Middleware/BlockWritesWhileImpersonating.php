<?php

namespace App\Http\Middleware;

use App\Services\Auth\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Önizleme (öğrenci olarak giriş) sırasında öğrenci adına hiçbir yazma işlemi yapılamaz
 * (şifre değiştirme, bildirim okundu vb.). Yalnız "Yönetime dön" ve çıkış serbesttir.
 */
class BlockWritesWhileImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::activeFor($request) && ! Impersonation::isWriteAllowed($request)) {
            return response()->json([
                'message' => 'Önizleme modundasınız; öğrenci adına değişiklik yapılamaz.',
                'error_code' => 'impersonation_read_only',
            ], 403);
        }

        return $next($request);
    }
}
