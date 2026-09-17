<?php

use App\Http\Controllers\Api\Placement\PlacementController;
use Illuminate\Support\Facades\Route;

/*
 * Sınıflar ve yerleştirme (9-12 × A/B). Görüntüleme academic.view; toplu işlemler academic.manage;
 * tekil sınıf değişimi academic.manage ya da students.update.
 */
Route::prefix('placement')->group(function () {
    Route::get('overview', [PlacementController::class, 'overview'])->middleware('permission:academic.view');
    Route::get('students/{student}', [PlacementController::class, 'studentPanel'])->middleware('permission:academic.view|students.view');

    Route::middleware(['permission:academic.manage', 'throttle:writes'])->group(function () {
        Route::post('preview', [PlacementController::class, 'preview']);
        Route::post('apply', [PlacementController::class, 'apply']);
        Route::post('runs/{run}/revert', [PlacementController::class, 'revert']);
        Route::post('students/{student}/pin', [PlacementController::class, 'pin']);
        Route::post('structure', [PlacementController::class, 'ensureStructure']);
        Route::put('settings', [PlacementController::class, 'updateSettings']);
        Route::post('promotion/preview', [PlacementController::class, 'promotionPreview']);
        Route::post('promotion/apply', [PlacementController::class, 'promotionApply']);
    });

    Route::middleware(['permission:academic.manage|students.update', 'throttle:writes'])->group(function () {
        Route::post('students/{student}/change/preview', [PlacementController::class, 'changePreview']);
        Route::post('students/{student}/change', [PlacementController::class, 'change']);
        Route::delete('waitlist/{entry}', [PlacementController::class, 'cancelWaitlist']);
    });
});
