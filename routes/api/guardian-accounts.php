<?php

use App\Http\Controllers\Api\Portal\GuardianAccountController;
use App\Http\Controllers\Api\Portal\ImpersonationController;
use Illuminate\Support\Facades\Route;

/*
| Personel tarafı: veli portal hesabı ve "veli olarak giriş yap" önizlemesi.
| (routes/api.php bu dosyayı 'staff' ara katmanıyla sarar.)
*/
Route::get('guardians/{guardian}/portal-account', [GuardianAccountController::class, 'show'])->middleware('permission:guardians.view');
Route::post('guardians/{guardian}/portal-account', [GuardianAccountController::class, 'store'])->middleware(['permission:guardians.credentials', 'throttle:writes']);
Route::get('guardians/{guardian}/portal-account/credentials', [GuardianAccountController::class, 'reveal'])->middleware(['permission:guardians.credentials', 'throttle:60,1']);
Route::post('guardians/{guardian}/portal-account/reset-password', [GuardianAccountController::class, 'resetPassword'])->middleware(['permission:guardians.credentials', 'throttle:writes']);
Route::post('guardians/{guardian}/impersonate', [ImpersonationController::class, 'startGuardian'])->middleware(['permission:guardians.impersonate', 'throttle:writes']);
