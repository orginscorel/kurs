<?php

use App\Http\Controllers\Devices\AdmsController;
use Illuminate\Support\Facades\Route;

/*
| ADMS / iclock — BİYOMETRİK TERMİNALİN KENDİSİ çağırır (docs/CIHAZ-KESIF.md).
|
| Oturumsuz ve CSRF'siz olmak ZORUNDA: terminalin ne çerezi ne jetonu vardır, düz HTTP konuşur.
| Kimlik, cihazın seri numarasıdır (?SN=) ve seri no ÖNCEDEN kayıtlı olmalıdır; tanınmayan
| seri no hiçbir veri yazamaz. Uçlar `devices_adms.enabled` kapalıyken (varsayılan: web
| sunucusu) her zaman "OK" döner ve hiçbir şey yapmaz.
|
| Yol web kökündedir (/iclock/...) çünkü cihaz menüsünde yalnız "sunucu adresi + port"
| girilebilir; ön ek verilemez.
*/
Route::prefix('iclock')
    ->middleware(['throttle:device'])
    /*
     | Web grubundan çıkarılanlar ve NEDENİ:
     |   ValidateCsrfToken → terminalde CSRF jetonu diye bir şey yoktur (POST'lar 419 alırdı).
     |   StartSession/cookie'ler → cihaz 10 saniyede bir sorar; her istekte oturum satırı
     |   açmak boşuna yazma yükü getirir (cihaz çerez de taşımaz).
     */
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \App\Http\Middleware\VerifyCsrfUnlessBearer::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    ])
    ->group(function () {
        Route::get('cdata', [AdmsController::class, 'handshake']);
        Route::post('cdata', [AdmsController::class, 'push']);
        Route::get('getrequest', [AdmsController::class, 'poll']);
        Route::post('devicecmd', [AdmsController::class, 'commandResult']);
        Route::get('ping', fn () => response("OK\n", 200, ['Content-Type' => 'text/plain']));
    });
