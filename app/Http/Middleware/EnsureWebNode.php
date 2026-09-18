<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * YALNIZ WEB işlemleri: masaüstü (yerel kurulum, KURS_NODE=local) bu işlemi yapsa sonucu web'e hiç ulaşmaz ya da
 * hiç yürümez (mesaj gönderimi, kampanya, şablon, otomasyon, sağlayıcı/webhook ayarı, ret listesi — tabloları düğüme
 * özel, bkz. SyncRegistry LOCAL). Sessizce kaybolmasın diye yerelde 409 `web_only` döner. Sunucuda etkisizdir.
 */
class EnsureWebNode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('kurs.node') !== 'local') {
            return $next($request);
        }

        return response()->json([
            'message' => 'Bu işlem web\'den yapılır: masaüstü uygulamasında yapılırsa sunucuya ulaşmaz. Kurumun web adresinde açın.',
            'error_code' => 'web_only', 'context' => [],
        ], 409);
    }
}
