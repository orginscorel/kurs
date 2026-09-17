<?php

use App\Http\Controllers\Api\Academic\ClassStructureController;
use Illuminate\Support\Facades\Route;

/*
| Sınıf yapısı sihirbazı (seviye/şube/alan/kapasite) + sınıfa özel müfredat.
| Görüntüleme academic.view; yapı ve müfredat değişiklikleri academic.manage.
*/
Route::middleware('permission:academic.view')->group(function () {
    Route::get('class-structure', [ClassStructureController::class, 'show']);
    Route::get('class-groups/{group}/curriculum', [ClassStructureController::class, 'curriculum']);
});

Route::middleware(['permission:academic.manage', 'throttle:writes'])->group(function () {
    Route::put('class-structure', [ClassStructureController::class, 'update']);
    Route::post('class-structure/apply', [ClassStructureController::class, 'apply']);
    Route::put('class-structure/track-curricula', [ClassStructureController::class, 'updateTrackCurricula']);
    Route::post('class-structure/apply-track', [ClassStructureController::class, 'applyTrack']);
    Route::put('class-groups/{group}/curriculum', [ClassStructureController::class, 'saveCurriculum']);
});
