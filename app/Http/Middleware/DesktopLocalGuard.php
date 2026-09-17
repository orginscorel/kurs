<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Masaüstü paketindeki yerel sunucuyu yalnız uygulama penceresine açar (ikinci katman; ilki paketteki router.php).
 *
 * Yalnız KURS_NODE=local VE KURS_DESKTOP_TOKEN_HASH tanımlıyken devreye girer (DesktopServiceProvider genel
 * ara katman olarak ekler). Web sunucusunda hiçbir şey yapmaz.
 *
 *  - Host yalnız 127.0.0.1 / localhost olabilir (DNS yeniden bağlama).
 *  - İstek, uygulamanın açılışta ürettiği jetonu `kurs_desktop` çerezinde ya da `X-Kurs-Desktop` başlığında taşır;
 *    ortamdaki SHA-256 özetiyle sabit zamanlı karşılaştırılır. Aynı makinedeki başka bir süreç ya da tarayıcıdaki
 *    bir web sayfası (CSRF / localhost taraması) jetonu bilmediği için istek yapamaz.
 *  - /__desktop/boot?t=… jetonu çereze yazar (router.php de aynısını yapar; FrankenPHP gibi başka sunucularda burası çalışır).
 */
class DesktopLocalGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $hash = (string) config('desktop.token_hash', '');
        if (config('kurs.node') !== 'local' || $hash === '') {
            return $next($request);
        }

        if (! preg_match('/^(127\.0\.0\.1|localhost|\[::1\])$/i', $request->getHost())) {
            return $this->deny(421, 'Bu yerel sunucu yalnız masaüstü uygulamasının penceresinden kullanılabilir.');
        }

        $cookie = (string) config('desktop.cookie', 'kurs_desktop');

        if ($request->getPathInfo() === '/__desktop/boot') {
            $token = (string) $request->query('t', '');
            if (! $this->matches($hash, $token)) {
                return $this->deny(403, 'Geçersiz ya da süresi dolmuş açılış bağlantısı.');
            }
            $target = (string) $request->query('next', '/');
            if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
                $target = '/';
            }

            // Mutlak adres isteğin kendi kökünden (APP_URL'e bağlı kalmasın; port her açılışta değişir)
            return (new RedirectResponse($request->getSchemeAndHttpHost().$target, 302, ['Cache-Control' => 'no-store']))
                ->withCookie(cookie($cookie, $token, 0, '/', null, false, true, false, 'strict'));
        }

        // Ham çerez: EncryptCookies bu ara katmandan sonra çalışır ve bu çerezi şifre çözümünden muaf tutar
        $presented = $request->cookies->get($cookie) ?? $request->headers->get((string) config('desktop.header', 'X-Kurs-Desktop'));
        if (! $this->matches($hash, is_string($presented) ? $presented : '')) {
            return $this->deny(403, 'Bu yerel sunucu yalnız Erbaa Bilgi Eğitim uygulamasının penceresinden kullanılabilir.');
        }

        return $next($request);
    }

    private function matches(string $hash, string $token): bool
    {
        return $token !== '' && strlen($hash) === 64 && hash_equals(strtolower($hash), hash('sha256', $token));
    }

    private function deny(int $status, string $message): Response
    {
        return response()->json(['message' => $message, 'error_code' => 'desktop_guard'], $status, ['Cache-Control' => 'no-store']);
    }
}
