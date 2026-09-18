<?php

namespace App\Http\Middleware;

use App\Models\DeviceIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminal (parmak izi / kart okuyucu) ayarları YALNIZ masaüstü uygulamasında (KURS_NODE=local) yapılır.
 *
 * Web sunucusu kurumun yerel ağındaki cihaza ulaşamaz: web'de ekleme/düzenleme/bağlantı testi/çekme/eşleştirme
 * ya hataya düşer ya da Mac'teki gerçek ayarla çelişen bir kayıt üretir. Cihaz kaydı ve eşlemeler Mac'te oluşur,
 * eşitlemeyle web'e (salt okunur) çıkar. Sunucuda bu rotalar 409 `terminal_desktop_only` döner.
 *
 * Parametre 'identity': öğrenci QR kimliği (kind=qr) web'de de yönetilebilir; yalnız parmak izi/kart engellenir.
 */
class EnsureTerminalDesktop
{
    public const MESSAGE = 'Terminal ayarları masaüstü uygulamasından yapılır. Web sunucusu kurumdaki cihaza ulaşamaz; ekleme, düzenleme, bağlantı testi, kayıt çekme ve eşleştirme kurumdaki Mac uygulamasında. Web bu bilgileri salt okunur gösterir.';

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (config('kurs.node') === 'local') {
            return $next($request);
        }
        if ($mode === 'identity' && $this->isQrIdentity($request)) {
            return $next($request);
        }

        return response()->json(['message' => self::MESSAGE, 'error_code' => 'terminal_desktop_only', 'context' => []], 409);
    }

    private function isQrIdentity(Request $request): bool
    {
        $identity = $request->route('identity');
        if ($identity !== null) {
            $kind = $identity instanceof DeviceIdentity ? $identity->kind
                : DeviceIdentity::query()->withoutGlobalScopes()->whereKey($identity)->value('kind');

            return $kind === 'qr';
        }
        if ($request->hasFile('file')) {
            return false;   // CSV toplu içe aktarma: parmak izi/kart satırı içerebilir
        }

        return $request->input('kind') === 'qr';
    }
}
