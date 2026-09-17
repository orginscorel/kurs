<?php

use App\Http\Controllers\Api\Portal\ImpersonationController;
use App\Http\Controllers\Api\Portal\TeacherAccountController;
use Illuminate\Support\Facades\Route;

/*
| Personel tarafı: öğretmen portal hesabı ve "öğretmen olarak giriş yap" önizlemesi.
| (routes/api.php bu dosyayı 'staff' ara katmanıyla sarar.)
*/
Route::get('teachers/{teacher}/portal-account', [TeacherAccountController::class, 'show'])->middleware('permission:teachers.view');
Route::post('teachers/{teacher}/portal-account', [TeacherAccountController::class, 'store'])->middleware(['permission:teachers.credentials', 'throttle:writes']);
Route::get('teachers/{teacher}/portal-account/credentials', [TeacherAccountController::class, 'reveal'])->middleware(['permission:teachers.credentials', 'throttle:60,1']);
Route::post('teachers/{teacher}/portal-account/reset-password', [TeacherAccountController::class, 'resetPassword'])->middleware(['permission:teachers.credentials', 'throttle:writes']);
Route::post('teachers/{teacher}/impersonate', [ImpersonationController::class, 'startTeacher'])->middleware(['permission:teachers.impersonate', 'throttle:writes']);
