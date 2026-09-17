<?php

use App\Http\Controllers\Api\Portal\ImpersonationController;
use App\Http\Controllers\Api\Portal\StudentAccountController;
use Illuminate\Support\Facades\Route;

/*
| Personel tarafı: öğrenci portal hesabı ve "öğrenci olarak giriş yap" önizlemesi.
| (routes/api.php bu dosyayı 'staff' ara katmanıyla sarar.)
*/
Route::get('students/{student}/portal-account', [StudentAccountController::class, 'show'])->middleware('permission:students.view');
Route::post('students/{student}/portal-account', [StudentAccountController::class, 'store'])->middleware('permission:students.credentials');
Route::get('students/{student}/portal-account/credentials', [StudentAccountController::class, 'reveal'])->middleware(['permission:students.credentials', 'throttle:60,1']);
Route::post('students/{student}/portal-account/reset-password', [StudentAccountController::class, 'resetPassword'])->middleware(['permission:students.credentials', 'throttle:writes']);
Route::post('students/{student}/impersonate', [ImpersonationController::class, 'start'])->middleware(['permission:students.impersonate', 'throttle:writes']);
