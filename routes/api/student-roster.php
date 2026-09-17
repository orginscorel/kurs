<?php

use App\Http\Controllers\Api\Students\StudentRosterController;
use Illuminate\Support\Facades\Route;

// Toplu öğrenci listesi / elle yoklama çizelgesi çıktısı (PDF; Excel ayrıca students.export ister)
Route::middleware('permission:students.view')->group(function () {
    Route::get('students-roster/options', [StudentRosterController::class, 'options']);
    Route::get('students-roster/preview', [StudentRosterController::class, 'preview']);
    Route::get('students-roster/export', [StudentRosterController::class, 'export'])->middleware('throttle:30,1');
});
