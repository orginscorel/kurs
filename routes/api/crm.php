<?php

use App\Http\Controllers\Api\Crm\CrmReportController;
use App\Http\Controllers\Api\Crm\LeadController;
use App\Http\Controllers\Api\Crm\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:crm.view')->group(function () {
    Route::get('crm/leads', [LeadController::class, 'index']);
    Route::get('crm/leads/board', [LeadController::class, 'board']);
    Route::get('crm/leads/options', [LeadController::class, 'options']);
    Route::get('crm/leads/summary', [LeadController::class, 'summary']);
    Route::get('crm/leads/export', [LeadController::class, 'export']);
    Route::get('crm/report', [CrmReportController::class, 'show']);
    Route::get('crm/leads/{lead}', [LeadController::class, 'show']);
});

Route::middleware(['permission:crm.manage', 'throttle:writes'])->group(function () {
    Route::post('crm/leads', [LeadController::class, 'store']);
    Route::put('crm/leads/{lead}', [LeadController::class, 'update']);
    Route::post('crm/leads/{lead}/move', [LeadController::class, 'move']);
    Route::post('crm/leads/{lead}/activities', [LeadController::class, 'storeActivity']);
    Route::post('crm/leads/{lead}/contact', [LeadController::class, 'contact']);
    Route::delete('crm/leads/{lead}', [LeadController::class, 'destroy']);
});

// Dönüştürme: aday üzerinde crm.manage + öğrenci oluşturma yetkisi (denetleyici içinde ayrıca doğrulanır).
Route::post('crm/leads/{lead}/convert', [LeadController::class, 'convert'])
    ->middleware(['permission:crm.manage', 'permission:students.create', 'throttle:writes']);

// Görevlerim: modüle özgü izin gerekmez — görev kime atanmışsa o kişi görür (bkz. TaskController).
Route::get('tasks/mine', [TaskController::class, 'mine']);
Route::post('tasks', [TaskController::class, 'store'])->middleware('throttle:writes');
Route::put('tasks/{task}', [TaskController::class, 'update']);
Route::post('tasks/{task}/complete', [TaskController::class, 'complete']);
Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen']);
Route::post('tasks/{task}/snooze', [TaskController::class, 'snooze']);
Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
