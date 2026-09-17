<?php

use App\Http\Controllers\Api\Sync\SyncPairController;
use App\Http\Middleware\VerifyCsrfUnlessBearer;
use Illuminate\Support\Facades\Route;

/*
| OTURUMSUZ: eşitleme sağlık yoklaması + cihaz eşleştirme (kurum kodu + personel kullanıcı adı/parolası).
| Eşleştirme çerez taşımayan masaüstü/mobil istemciden gelir → CSRF muaf; giriş hız sınırı uygulanır.
*/
Route::get('sync/ping', [SyncPairController::class, 'ping'])->middleware('throttle:60,1');
Route::post('sync/pair', [SyncPairController::class, 'pair'])->middleware('throttle:login')
    ->withoutMiddleware([VerifyCsrfUnlessBearer::class, \Illuminate\Session\Middleware\StartSession::class, \App\Http\Middleware\StartSessionUnlessBearer::class]);
