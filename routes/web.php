<?php

use Illuminate\Support\Facades\Route;

/*
| Uygulama tek sayfalık (SPA). Sunucu yalnızca kabuğu döner; yönlendirme istemcide yapılır.
| /api, /sanctum, /build, /storage ve /up dışındaki tüm yollar kabuğa düşer.
*/

Route::get('/sanctum/csrf-cookie', [\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show']);

/*
| ADMS/iclock: biyometrik terminalin KENDİSİNİN çağırdığı oturumsuz uçlar (routes/iclock.php).
| Cihaz menüsünde yalnız "sunucu adresi + port" girilebildiği için yol web kökündedir.
*/
require __DIR__.'/iclock.php';

Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|storage|build|up|iclock).*$');
