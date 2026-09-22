<?php

use App\Http\Controllers\Api\Coaching\CoachingAssignmentController;
use App\Http\Controllers\Api\Coaching\CoachingDashboardController;
use App\Http\Controllers\Api\Coaching\CoachingPlanController;
use App\Http\Controllers\Api\Coaching\CoachingSessionController;
use Illuminate\Support\Facades\Route;

// Görüntüleme
Route::middleware('permission:coaching.view')->group(function () {
    Route::get('coaching/dashboard', [CoachingDashboardController::class, 'index']);
    Route::get('coaching/students/{student}/summary', [CoachingDashboardController::class, 'student']);

    Route::get('coaching/assignments', [CoachingAssignmentController::class, 'index']);
    Route::get('coaching/assignments/options', [CoachingAssignmentController::class, 'options']);

    Route::get('coaching/sessions', [CoachingSessionController::class, 'index']);
    Route::get('coaching/sessions/{session}', [CoachingSessionController::class, 'show']);

    Route::get('coaching/plans', [CoachingPlanController::class, 'index']);
    Route::get('coaching/students/{student}/plans', [CoachingPlanController::class, 'forStudent']);
});

// Yönetim (yazma)
Route::middleware(['permission:coaching.manage', 'throttle:writes'])->group(function () {
    Route::post('coaching/students/{student}/coach', [CoachingAssignmentController::class, 'store']);
    Route::delete('coaching/students/{student}/coach', [CoachingAssignmentController::class, 'destroy']);

    Route::post('coaching/sessions', [CoachingSessionController::class, 'store']);
    Route::put('coaching/sessions/{session}', [CoachingSessionController::class, 'update']);
    Route::delete('coaching/sessions/{session}', [CoachingSessionController::class, 'destroy']);

    Route::post('coaching/plans', [CoachingPlanController::class, 'store']);
    Route::put('coaching/plans/{plan}', [CoachingPlanController::class, 'update']);
    Route::delete('coaching/plans/{plan}', [CoachingPlanController::class, 'destroy']);
    Route::post('coaching/plan-items/{item}/toggle', [CoachingPlanController::class, 'toggleItem']);
});
