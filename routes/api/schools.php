<?php

use App\Http\Controllers\Api\Settings\SchoolController;
use Illuminate\Support\Facades\Route;

// Okul listesi: öneri tüm personele açık; düzenleme kurum ayarı yetkisiyle
Route::get('schools/options', [SchoolController::class, 'options']);

Route::middleware('permission:settings.manage')->group(function () {
    Route::get('schools', [SchoolController::class, 'index']);
    Route::post('schools', [SchoolController::class, 'store'])->middleware('throttle:writes');
    Route::put('schools/{school}', [SchoolController::class, 'update'])->whereNumber('school');
    Route::delete('schools/{school}', [SchoolController::class, 'destroy'])->whereNumber('school');
    Route::post('schools/import-regions', [SchoolController::class, 'importRegions'])->middleware('throttle:writes');
});
