<?php

use App\Http\Controllers\Api\Staff\EmployeeController;
use App\Http\Controllers\Api\Staff\TeacherController;
use App\Http\Controllers\Api\Staff\TeacherPanelController;
use Illuminate\Support\Facades\Route;

Route::get('teacher-panel/summary', [TeacherPanelController::class, 'summary'])->middleware('permission:schedule.view');

Route::middleware('permission:teachers.view')->group(function () {
    Route::get('teachers', [TeacherController::class, 'index']);
    Route::get('teachers/options', [TeacherController::class, 'options']);
    Route::get('teachers/export', [TeacherController::class, 'export']);
    Route::get('teachers/{teacher}', [TeacherController::class, 'show']);
});

Route::middleware('permission:teachers.manage')->group(function () {
    Route::post('teachers', [TeacherController::class, 'store'])->middleware('throttle:writes');
    Route::put('teachers/{teacher}', [TeacherController::class, 'update']);
    Route::delete('teachers/{teacher}', [TeacherController::class, 'destroy']);
    Route::post('teachers/{teacher}/photo', [TeacherController::class, 'uploadPhoto']);

    Route::post('teachers/{teacher}/leaves', [TeacherController::class, 'leavesStore']);
    Route::put('teachers/{teacher}/leaves/{leave}', [TeacherController::class, 'leavesUpdate']);
    Route::delete('teachers/{teacher}/leaves/{leave}', [TeacherController::class, 'leavesDestroy']);

    Route::post('teachers/{teacher}/documents', [TeacherController::class, 'documentsStore']);
    Route::get('teachers/{teacher}/documents/{document}/download', [TeacherController::class, 'documentsDownload']);
    Route::delete('teachers/{teacher}/documents/{document}', [TeacherController::class, 'documentsDestroy']);
});

Route::middleware('permission:employees.view')->group(function () {
    Route::get('employees', [EmployeeController::class, 'index']);
    Route::get('employees/options', [EmployeeController::class, 'options']);
    Route::get('employees/{employee}', [EmployeeController::class, 'show']);
});
Route::middleware('permission:employees.manage')->group(function () {
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('throttle:writes');
    Route::put('employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);
});
