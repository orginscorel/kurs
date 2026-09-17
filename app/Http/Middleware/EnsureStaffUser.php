<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yönetim uçları yalnız personel/öğretmen hesaplarına açıktır. Öğrenci ve veli hesapları
 * (yetkisi olmayan uçlar dahil) buraya hiç giremez; yalnız routes/api/portal.php kullanır.
 */
class EnsureStaffUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isStaff()) {
            return response()->json([
                'message' => 'Bu işlem için yetkiniz yok.',
                'error_code' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
