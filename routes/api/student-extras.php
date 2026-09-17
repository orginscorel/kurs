<?php

use App\Http\Controllers\Api\Students\GuardianMergeController;
use App\Http\Controllers\Api\Students\StudentObservationController;
use Illuminate\Support\Facades\Route;

/*
| Öğrenci profili ekleri ve veli bakımı (routes/api.php bu dosyayı 'staff' ara katmanıyla sarar).
|  - Gözlemler: öğretmen portalında girilen gözlem notları (salt okunur)
|  - Mükerrer veli: aynı telefonlu veli grupları, birleştirme önizlemesi ve onaylı birleştirme
*/
Route::get('students/{student}/observations', [StudentObservationController::class, 'index'])->middleware('permission:students.view');

Route::middleware('permission:guardians.view')->group(function () {
    Route::get('guardians-duplicates', [GuardianMergeController::class, 'duplicates']);
});
Route::middleware(['permission:guardians.manage'])->group(function () {
    Route::post('guardians-merge/preview', [GuardianMergeController::class, 'preview']);
    Route::post('guardians-merge', [GuardianMergeController::class, 'merge'])->middleware('throttle:writes');
});
