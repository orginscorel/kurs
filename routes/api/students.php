<?php

use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\StudentController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:students.view')->group(function () {
    Route::get('students', [StudentController::class, 'index']);
    Route::get('students/options', [StudentController::class, 'options']);
    Route::get('students/export', [StudentController::class, 'export'])->middleware('permission:students.export');
    Route::get('students/{student}', [StudentController::class, 'show']);
    Route::get('students/{student}/attendance', [StudentController::class, 'attendance']);
    Route::get('students/{student}/payments', [StudentController::class, 'payments']);
    Route::get('students/{student}/timeline', [StudentController::class, 'timeline']);
    Route::get('students/{student}/notes', [StudentController::class, 'notes']);
    Route::post('students/{student}/notes', [StudentController::class, 'storeNote']);
    Route::delete('students/{student}/notes/{note}', [StudentController::class, 'destroyNote']);
    Route::get('students/{student}/homework', [StudentController::class, 'homework']);
    Route::get('students/{student}/messages', [StudentController::class, 'messages']);
    Route::get('students/{student}/national-id', [StudentController::class, 'revealNationalId'])->middleware('permission:students.view_sensitive');
});

Route::post('students', [StudentController::class, 'store'])->middleware(['permission:students.create', 'throttle:writes']);
Route::put('students/{student}', [StudentController::class, 'update'])->middleware('permission:students.update');
Route::post('students/{student}/status', [StudentController::class, 'changeStatus'])->middleware('permission:students.update');
Route::post('students/{student}/photo', [StudentController::class, 'uploadPhoto'])->middleware('permission:students.update');
Route::post('students-bulk', [StudentController::class, 'bulk'])->middleware('permission:students.bulk');
Route::delete('students/{student}', [StudentController::class, 'destroy'])->middleware('permission:students.delete');

Route::middleware('permission:guardians.view')->group(function () {
    Route::get('guardians', [GuardianController::class, 'index']);
    Route::get('guardians/{guardian}', [GuardianController::class, 'show']);
});
Route::put('guardians/{guardian}', [GuardianController::class, 'update'])->middleware('permission:guardians.manage');
