<?php

namespace App\Providers;

use App\Http\Middleware\DesktopLocalGuard;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\ServiceProvider;

/**
 * Masaüstü paketi (desktop/, docs: desktop/docs/DESKTOP.md).
 * Web sunucusunda etkisizdir; yalnız KURS_NODE=local + KURS_DESKTOP_TOKEN_HASH tanımlıyken yerel sunucu
 * koruma ara katmanını en başa ekler.
 */
class DesktopServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (config('kurs.node') !== 'local' || ! config('desktop.token_hash')) {
            return;
        }

        // Jeton çerezi Laravel tarafından şifrelenmez (paketteki router.php ham değeri okur/yazar)
        EncryptCookies::except([(string) config('desktop.cookie', 'kurs_desktop')]);

        if (! $this->app->runningInConsole()) {
            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(DesktopLocalGuard::class);
            }
        }
    }
}
