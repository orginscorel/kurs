<?php

use App\Http\Controllers\Api\Attendance\AbsenceController;
use App\Http\Controllers\Api\Attendance\AttendanceTakingController;
use App\Http\Controllers\Api\Attendance\DeviceController;
use App\Http\Controllers\Api\Attendance\DeviceIdentityController;
use App\Http\Controllers\Api\Attendance\LivePresenceController;
use App\Http\Controllers\Api\Attendance\StudentQrController;
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
        Route::post('devices', [DeviceController::class, 'store'])->middleware('throttle:writes');
        Route::put('devices/{device}', [DeviceController::class, 'update'])->middleware('throttle:writes');
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->middleware('throttle:writes');
        Route::post('devices/{device}/token', [DeviceController::class, 'issueToken'])->middleware('throttle:writes');

        // Biyometrik terminal köprüsü (ZKTeco / Perkotek YT-33) — docs/CIHAZ-KOPRUSU.md
        Route::prefix('zk')->group(function () {
            Route::post('test', [ZkDeviceController::class, 'test'])->middleware('throttle:writes');
            Route::post('cihazlar/{device}/baglanti', [ZkDeviceController::class, 'saveConnection'])->middleware('throttle:writes');
            Route::post('cihazlar/{device}/test', [ZkDeviceController::class, 'test'])->middleware('throttle:writes');
            Route::post('cihazlar/{device}/cek', [ZkDeviceController::class, 'pullNow'])->middleware('throttle:writes');
            Route::get('cihazlar/{device}/durum', [ZkDeviceController::class, 'status']);
            Route::get('cihazlar/{device}/kullanicilar', [ZkDeviceController::class, 'users']);
            Route::get('eslemeler', [ZkDeviceController::class, 'mappings']);
            Route::post('eslemeler', [ZkDeviceController::class, 'storeMapping'])->middleware('throttle:writes');
            Route::delete('eslemeler/{identity}', [ZkDeviceController::class, 'destroyMapping'])->middleware('throttle:writes');
            Route::get('bekleyenler', [ZkDeviceController::class, 'pending']);
        });

        Route::get('identities', [DeviceIdentityController::class, 'index']);
        Route::post('identities', [DeviceIdentityController::class, 'store'])->middleware('throttle:writes');
        Route::delete('identities/{identity}', [DeviceIdentityController::class, 'destroy'])->middleware('throttle:writes');
        Route::post('identities/import', [DeviceIdentityController::class, 'import'])->middleware('throttle:writes');
    });
});
