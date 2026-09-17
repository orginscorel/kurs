<?php

use App\Http\Controllers\Api\Guidance\GuidanceMeetingController;
use App\Http\Controllers\Api\Guidance\RiskController;
use App\Http\Controllers\Api\Guidance\StudentGoalController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:guidance.view')->group(function () {
    Route::get('guidance/meetings', [GuidanceMeetingController::class, 'index']);
    Route::get('guidance/meetings/options', [GuidanceMeetingController::class, 'options']);
    Route::get('guidance/meetings/{meeting}', [GuidanceMeetingController::class, 'show']);
    Route::get('guidance/students/{student}/report.pdf', [GuidanceMeetingController::class, 'reportPdf']);
    Route::get('guidance/students/{student}/goals', [StudentGoalController::class, 'forStudent']);
    Route::get('guidance/goals', [StudentGoalController::class, 'index']);
    // Hedef takibi filtre seçenekleri (risk.view gerektirmez; risk verisi içermez)
    Route::get('guidance/goals/options', [StudentGoalController::class, 'options']);
});

Route::middleware(['permission:guidance.manage', 'throttle:writes'])->group(function () {
    Route::post('guidance/meetings', [GuidanceMeetingController::class, 'store']);
    Route::put('guidance/meetings/{meeting}', [GuidanceMeetingController::class, 'update']);
    Route::delete('guidance/meetings/{meeting}', [GuidanceMeetingController::class, 'destroy']);
    Route::post('guidance/goals', [StudentGoalController::class, 'store']);
    Route::put('guidance/goals/{goal}', [StudentGoalController::class, 'update']);
    Route::delete('guidance/goals/{goal}', [StudentGoalController::class, 'destroy']);
});

Route::middleware('permission:risk.view')->group(function () {
    Route::get('risk/students', [RiskController::class, 'index']);
    Route::get('risk/options', [RiskController::class, 'options']);
    Route::post('risk/students/{student}/recalculate', [RiskController::class, 'recalculate']);
    // guidance.manage denetleyici içinde ayrıca doğrulanır (görev + görüşme oluşturur).
    Route::post('risk/students/{student}/plan-meeting', [RiskController::class, 'planMeeting']);
});
