<?php

use App\Http\Controllers\Api\ClassroomDesign\ClassroomLayoutController;
use App\Http\Controllers\Api\ClassroomDesign\SeatingPlanController;
use Illuminate\Support\Facades\Route;

/*
| 3D derslik tasarımı ve oturma düzeni (modül: resources/js/modules/classroom-design).
| Okuma: academic.view · yazma: classroom_layouts.manage
*/
Route::middleware('permission:academic.view')->group(function () {
    Route::get('classroom-layouts', [ClassroomLayoutController::class, 'index']);
    Route::get('classroom-layouts/roster', [ClassroomLayoutController::class, 'roster']);
    Route::get('classroom-layouts/{layout}', [ClassroomLayoutController::class, 'show'])->whereNumber('layout');
    Route::get('classroom-layouts/{layout}/thumbnail', [ClassroomLayoutController::class, 'thumbnail'])->whereNumber('layout');
    Route::get('classroom-layouts/{layout}/versions', [ClassroomLayoutController::class, 'versions'])->whereNumber('layout');
    // Sınıf oturma planı (oda düzeninden ayrı)
    Route::get('class-seating/overview', [SeatingPlanController::class, 'overview']);
    Route::get('class-groups/{group}/seating', [SeatingPlanController::class, 'show'])->whereNumber('group');
});

Route::put('class-groups/{group}/seating', [SeatingPlanController::class, 'update'])->whereNumber('group')
    ->middleware(['permission:academic.manage|classroom_layouts.manage', 'throttle:writes']);

Route::middleware(['permission:classroom_layouts.manage', 'throttle:writes'])->group(function () {
    Route::post('classroom-layouts', [ClassroomLayoutController::class, 'store']);
    Route::put('classroom-layouts/{layout}', [ClassroomLayoutController::class, 'update'])->whereNumber('layout');
    Route::put('classroom-layouts/{layout}/thumbnail', [ClassroomLayoutController::class, 'updateThumbnail'])->whereNumber('layout');
    Route::post('classroom-layouts/{layout}/versions/{version}/restore', [ClassroomLayoutController::class, 'restore'])->whereNumber('layout')->whereNumber('version');
    Route::delete('classroom-layouts/{layout}', [ClassroomLayoutController::class, 'destroy'])->whereNumber('layout');
});
