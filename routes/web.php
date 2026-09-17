<?php

use Illuminate\Support\Facades\Route;

/*
| Uygulama tek sayfalık (SPA). Sunucu yalnızca kabuğu döner; yönlendirme istemcide yapılır.
| /api, /sanctum, /build, /storage ve /up dışındaki tüm yollar kabuğa düşer.
*/

Route::get('/sanctum/csrf-cookie', [\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show']);

Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|storage|build|up).*$');
