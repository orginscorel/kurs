<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceGatewayController;
use Illuminate\Support\Facades\Route;

/*
| API v1 — web paneli ve mobil uygulamaların ortak arayüzü.
| Modül rotaları routes/api/*.php dosyalarındadır ve otomatik yüklenir; her modül
| kendi dosyasına sahip olduğundan paralel geliştirmede aynı dosya düzenlenmez.
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/token', [AuthController::class, 'issueToken'])->middleware('throttle:login');

    // Oturumsuz uçlar (sağlayıcı webhook'ları vb.): routes/api-public/*.php — kendi imza/jeton doğrulamasını yapmalı
    foreach (glob(__DIR__.'/api-public/*.php') as $file) {
        require $file;
    }

    // Donanım köprüsü (parmak izi / RFID / QR terminalleri) — cihaz jetonu ile
    Route::middleware(['device', 'throttle:device'])->prefix('gateway')->group(function () {
        Route::post('events', [DeviceGatewayController::class, 'events']);
        Route::get('identities', [DeviceGatewayController::class, 'identities']);
        Route::post('heartbeat', [DeviceGatewayController::class, 'heartbeat']);
    });

    // password.fresh: öğrenci/veli başlangıç şifresini değiştirmeden portalı kullanamaz (önizleme muaf)
    Route::middleware(['auth:sanctum', 'active', 'impersonation.readonly', 'password.fresh'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:login');
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [AuthController::class, 'revokeSession']);
        // Önizlemeden ("öğrenci/veli olarak giriş") yönetime dönüş — oturumdaki portal hesabıyla çağrılır
        Route::post('auth/impersonation/leave', [\App\Http\Controllers\Api\Portal\ImpersonationController::class, 'leave']);

        /*
         * Öğrenci/veli/öğretmen-portal hesapları yalnız PORTAL_FILES'a girer; diğer tüm modül dosyaları
         * 'staff' ara katmanıyla sarılır (yetki anahtarı unutulmuş bir uç bile portal hesabına kapalı kalır).
         * core.php: bildirimler kullanıcıya özeldir, diğer uçları yetki anahtarlıdır.
         * teacher-portal.php: her uç 'portal.teacher' ile öğretmenin kendi kapsamına kilitlidir.
         */
        $portalFiles = ['portal.php', 'core.php', 'teacher-portal.php'];
        foreach (glob(__DIR__.'/api/*.php') as $file) {
            if (in_array(basename($file), $portalFiles, true)) {
                require $file;
            } else {
                Route::middleware('staff')->group($file);
            }
        }
    });
});
