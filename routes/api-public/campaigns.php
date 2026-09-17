<?php

use App\Http\Controllers\Public\UnsubscribeController;
use App\Http\Middleware\VerifyCsrfUnlessBearer;
use Illuminate\Support\Facades\Route;

/*
| OTURUMSUZ: toplu e-posta abonelikten çıkma. Kimlik imzalı jetonla doğrulanır (UnsubscribeToken).
| POST CSRF'siz: e-posta istemcilerinin "tek tık" (RFC 8058 List-Unsubscribe-Post) isteği çerez/jeton taşımaz.
*/
Route::get('abonelik/{token}', [UnsubscribeController::class, 'show'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9_.-]+');
Route::post('abonelik/{token}', [UnsubscribeController::class, 'store'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9_.-]+')
    ->withoutMiddleware([VerifyCsrfUnlessBearer::class]);
