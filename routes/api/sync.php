<?php

use App\Http\Controllers\Api\Sync\LocalStatusController;
use App\Http\Controllers\Api\Sync\SyncAdminController;
use App\Http\Controllers\Api\Sync\SyncDeviceController;
use App\Http\Controllers\Api\Sync\SyncFileController;
use App\Http\Controllers\Api\Sync\SyncReportController;
use App\Http\Controllers\Api\Sync\SyncSessionController;
use App\Http\Middleware\StartSessionUnlessBearer;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/*
| Çevrimdışı yerel kurulum / mobil ↔ web eşitlemesi. Protokol ve kurallar: docs/SYNC.md
| Bu dosya 'staff' ara katmanıyla sarılır: öğrenci/veli/öğretmen-portalı hesapları giremez.
*/

Route::prefix('sync')->group(function () {
    // Cihaz uçları: yalnız eşleştirilmiş cihaz jetonu (yetenek 'sync'). Oturum açılmaz (her istekte sessions satırı birikmesin).
    Route::middleware(['sync.device', 'permission:sync.use', 'throttle:sync'])
        ->withoutMiddleware([StartSession::class, StartSessionUnlessBearer::class])->group(function () {
            Route::get('status', [SyncDeviceController::class, 'status']);
            Route::get('pull', [SyncDeviceController::class, 'pull']);
            Route::post('push', [SyncDeviceController::class, 'push']);
            Route::get('snapshot', [SyncDeviceController::class, 'manifest']);
            Route::get('snapshot/{table}', [SyncDeviceController::class, 'snapshot'])->where('table', '[a-z_]+');
            Route::post('rows', [SyncDeviceController::class, 'rows']);
            Route::post('number-blocks', [SyncDeviceController::class, 'numberBlock']);
            Route::post('key-bundle', [SyncDeviceController::class, 'keyBundle']);
            // Parola: yalnız çevrimiçi, mevcut parola sunucuda doğrulanır (kuyruğa alınmaz)
            Route::post('password', [SyncDeviceController::class, 'password'])->middleware('throttle:10,1');
            // Dosyalar: içerik adresli (sha256)
            Route::get('files/manifest', [SyncFileController::class, 'manifest']);
            Route::get('files/{sha256}', [SyncFileController::class, 'download'])->where('sha256', '[0-9a-f]{64}');
            Route::post('files', [SyncFileController::class, 'upload']);
            // Açık oturumlar: cihazın yerel oturum raporu (+ bekleyen uzaktan kapatmalar) ve uygulamadaki kullanıcının tam listesi
            Route::post('sessions', [SyncSessionController::class, 'report']);
            Route::get('user-sessions', [SyncSessionController::class, 'userSessions']);
            Route::post('user-sessions/revoke', [SyncSessionController::class, 'revoke'])->middleware('throttle:30,1');
            // Küçük durum raporları: terminal köprüsünün son durumu (web ekranında "… üzerinden") + masaüstünde okunan bildirimler
            Route::post('terminal-status', [SyncReportController::class, 'terminalStatus']);
            Route::post('terminal-diag', [\App\Http\Controllers\Api\Sync\TerminalDiagController::class, 'upload']);
            Route::post('notification-reads', [SyncReportController::class, 'notificationReads']);
        });

    // Yönetim: Bağlı cihazlar + Eşitleme çakışmaları
    Route::middleware('permission:sync.manage')->group(function () {
        Route::get('overview', [SyncAdminController::class, 'overview']);
        Route::get('devices', [SyncAdminController::class, 'devices']);
        Route::post('devices/{device}/revoke', [SyncAdminController::class, 'revoke'])->whereNumber('device');
        Route::get('pairing-code', [SyncAdminController::class, 'pairingCode']);
        Route::post('pairing-code/rotate', [SyncAdminController::class, 'rotatePairingCode']);
        Route::get('conflicts', [SyncAdminController::class, 'conflicts']);
        Route::get('conflicts/{conflict}', [SyncAdminController::class, 'conflict'])->whereNumber('conflict');
        Route::post('conflicts/{conflict}/resolve', [SyncAdminController::class, 'resolve'])->whereNumber('conflict');
        Route::get('terminal-diag', [\App\Http\Controllers\Api\Sync\TerminalDiagController::class, 'index']);
        Route::get('terminal-diag/{id}/indir', [\App\Http\Controllers\Api\Sync\TerminalDiagController::class, 'download'])->whereNumber('id');
    });

    // Yerel düğüm göstergesi (sunucuda yalnız {node: server} döner)
    Route::get('local-status', [LocalStatusController::class, 'show']);
    Route::post('local-sync-now', [LocalStatusController::class, 'syncNow']);
    // Reddedilen yerel değişiklikler: liste + yeniden dene (yalnız yerel düğüm)
    Route::get('local-rejected', [LocalStatusController::class, 'rejected']);
    Route::post('local-rejected/retry', [LocalStatusController::class, 'retryRejected']);
});
