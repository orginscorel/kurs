<?php

use App\Http\Controllers\Api\Attendance\AbsenceController;
use App\Http\Controllers\Api\Attendance\AttendanceTakingController;
use App\Http\Controllers\Api\Attendance\DeviceController;
use App\Http\Controllers\Api\Attendance\DeviceDiscoveryController;
use App\Http\Controllers\Api\Attendance\DeviceIdentityController;
use App\Http\Controllers\Api\Attendance\LivePresenceController;
use App\Http\Controllers\Api\Attendance\StudentQrController;
use App\Http\Controllers\Api\Attendance\TerminalController;
use App\Http\Controllers\Api\Attendance\ZkDeviceController;
use Illuminate\Support\Facades\Route;

/*
| Yoklama modülü — canlı giriş/çıkış, hızlı yoklama, devamsızlık, cihazlar, QR.
| Donanım köprüsü uçları routes/api.php içinde "gateway" ön ekiyle ayrı (cihaz jetonu).
*/

Route::prefix('attendance')->group(function () {
    Route::middleware('permission:presence.live')->group(function () {
        Route::get('live', [LivePresenceController::class, 'index']);
        Route::get('live/feed', [LivePresenceController::class, 'feed']);
        Route::get('live/unmatched', [LivePresenceController::class, 'unmatched']);
        Route::post('live/unmatched/{event}/match', [LivePresenceController::class, 'match']);
        Route::post('live/manual', [LivePresenceController::class, 'manual'])->middleware('throttle:writes');
        Route::post('live/scan', [LivePresenceController::class, 'scan'])->middleware('throttle:writes');
    });

    Route::middleware('permission:attendance.view')->group(function () {
        Route::get('sessions', [AttendanceTakingController::class, 'sessions']);
        Route::get('sessions/{session}', [AttendanceTakingController::class, 'roster']);
        Route::get('absences', [AbsenceController::class, 'index']);
        Route::get('absences/options', [AbsenceController::class, 'options']);
        Route::get('absences/export', [AbsenceController::class, 'export']);
        Route::get('students/{student}/qr', [StudentQrController::class, 'show']);
    });

    Route::post('sessions/{session}', [AttendanceTakingController::class, 'store'])
        ->middleware(['permission:attendance.take', 'throttle:writes']);

    Route::post('absences/leave', [AbsenceController::class, 'leave'])
        ->middleware(['permission:attendance.override', 'throttle:writes']);
    Route::post('students/{student}/qr/regenerate', [StudentQrController::class, 'regenerate'])
        ->middleware(['permission:attendance.override', 'throttle:writes']);

    Route::middleware('permission:devices.manage')->group(function () {
        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices', [DeviceController::class, 'store'])->middleware(['terminal.desktop', 'throttle:writes']);
        Route::put('devices/{device}', [DeviceController::class, 'update'])->middleware(['terminal.desktop', 'throttle:writes']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->middleware(['terminal.desktop', 'throttle:writes']);
        Route::post('devices/{device}/token', [DeviceController::class, 'issueToken'])->middleware(['terminal.desktop', 'throttle:writes']);

        /*
         | AĞDA CİHAZ BUL (docs/CIHAZ-KESIF.md) — elle IP/JSON yazmayı bitiren uçlar.
         | Tarama yalnız cihazla aynı yerel ağdaki makinede sonuç verir; sunucuda çağrılırsa
         | yanıt yine temiz döner ve `ortam.uyari` nedeni Türkçe açıklar.
         | "kesif" segmenti {device} parametresiyle ÇAKIŞMAZ (farklı segment sayısı/ad).
         */
        Route::get('devices/protokoller', [DeviceDiscoveryController::class, 'protocols']);
        Route::get('devices/teshis', [DeviceDiscoveryController::class, 'diagnostics']);
        Route::get('devices/kesif/ortam', [DeviceDiscoveryController::class, 'environment']);
        Route::post('devices/kesif/tara', [DeviceDiscoveryController::class, 'scan'])->middleware(['terminal.desktop', 'throttle:writes']);
        Route::post('devices/kesif/dene', [DeviceDiscoveryController::class, 'probe'])->middleware(['terminal.desktop', 'throttle:writes']);
        Route::post('devices/kesif/ekle', [DeviceDiscoveryController::class, 'register'])->middleware(['terminal.desktop', 'throttle:writes']);

        // Biyometrik terminal köprüsü (ZKTeco / Perkotek YT-33) — docs/CIHAZ-KOPRUSU.md
        Route::prefix('zk')->group(function () {
            // Yazma/cihaza bağlanma uçları yalnız masaüstünde (terminal.desktop); okuma uçları web'de de açık
            Route::post('test', [ZkDeviceController::class, 'test'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::post('cihazlar/{device}/baglanti', [ZkDeviceController::class, 'saveConnection'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::post('cihazlar/{device}/test', [ZkDeviceController::class, 'test'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::post('cihazlar/{device}/cek', [ZkDeviceController::class, 'pullNow'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::get('cihazlar/{device}/durum', [ZkDeviceController::class, 'status']);
            Route::get('cihazlar/{device}/kullanicilar', [ZkDeviceController::class, 'users'])->middleware('terminal.desktop');
            Route::get('eslemeler', [ZkDeviceController::class, 'mappings']);
            Route::post('eslemeler', [ZkDeviceController::class, 'storeMapping'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::delete('eslemeler/{identity}', [ZkDeviceController::class, 'destroyMapping'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::get('bekleyenler', [ZkDeviceController::class, 'pending']);
        });

        /*
         | TERMİNAL KÖPRÜSÜ (sürücü bağımsız: ZKTeco / Perkotek YT33-FK / Genel TCP) — docs/CIHAZ-KOPRUSU.md
         | Ayar, iki aşamalı test, ham TCP tanılaması ve teşhis YALNIZ masaüstünde (terminal.desktop).
         | Sunucu özel ağ adreslerine asla bağlanmaya çalışmaz; web yalnız sır içermeyen durumu okur.
         */
        Route::prefix('terminal')->group(function () {
            Route::get('suruculer', [TerminalController::class, 'drivers']);
            Route::get('cihazlar/{device}', [TerminalController::class, 'show']);
            Route::post('cihazlar/{device}/ayar', [TerminalController::class, 'save'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::post('cihazlar/{device}/test', [TerminalController::class, 'test'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::post('test', [TerminalController::class, 'test'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::post('ham-tani', [TerminalController::class, 'rawDiagnostic'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::get('ham-tani/{id}/indir', [TerminalController::class, 'downloadDiagnostic'])->middleware('terminal.desktop')->where('id', '[0-9a-f]{12}');
            Route::get('teshis', [TerminalController::class, 'diagnostics'])->middleware('terminal.desktop');
            Route::get('push', [TerminalController::class, 'push'])->middleware('terminal.desktop');
            Route::post('push', [TerminalController::class, 'savePush'])->middleware(['terminal.desktop', 'throttle:writes']);
            Route::get('paketler', [TerminalController::class, 'packets'])->middleware('terminal.desktop');
            Route::get('paketler/{id}', [TerminalController::class, 'packet'])->middleware('terminal.desktop')->whereNumber('id');
            Route::get('paketler/{id}/indir', [TerminalController::class, 'downloadPacket'])->middleware('terminal.desktop')->whereNumber('id');
        });

        Route::get('identities', [DeviceIdentityController::class, 'index']);
        Route::post('identities', [DeviceIdentityController::class, 'store'])->middleware(['terminal.desktop:identity', 'throttle:writes']);
        Route::delete('identities/{identity}', [DeviceIdentityController::class, 'destroy'])->middleware(['terminal.desktop:identity', 'throttle:writes']);
        Route::post('identities/import', [DeviceIdentityController::class, 'import'])->middleware(['terminal.desktop:identity', 'throttle:writes']);
    });
});
