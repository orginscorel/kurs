<?php

use App\Http\Controllers\Api\Settings\AuditLogController;
use App\Http\Controllers\Api\Settings\BackupController;
use App\Http\Controllers\Api\Settings\InstitutionController;
use App\Http\Controllers\Api\Settings\RoleController;
use App\Http\Controllers\Api\Settings\SystemHealthController;
use App\Http\Controllers\Api\Settings\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:settings.manage')->group(function () {
    Route::get('settings/{group}', [InstitutionController::class, 'show'])->whereAlpha('group');
    Route::put('settings/{group}', [InstitutionController::class, 'update'])->whereAlpha('group');
    Route::post('settings-institution/logo', [InstitutionController::class, 'uploadLogo']);

    Route::get('academic-terms', [InstitutionController::class, 'terms']);
    Route::post('academic-terms', [InstitutionController::class, 'termsStore']);
    Route::put('academic-terms/{term}', [InstitutionController::class, 'termsUpdate']);
    Route::post('academic-terms/{term}/set-current', [InstitutionController::class, 'termsSetCurrent']);

    Route::get('branches', [InstitutionController::class, 'branches']);
    Route::post('branches', [InstitutionController::class, 'branchesStore']);
    Route::put('branches/{branch}', [InstitutionController::class, 'branchesUpdate']);

    Route::get('onboarding/status', [InstitutionController::class, 'onboardingStatus']);
    Route::post('onboarding/complete', [InstitutionController::class, 'onboardingComplete']);
});

Route::middleware('permission:users.manage')->group(function () {
    Route::get('admin-users', [UserController::class, 'index']);
    Route::get('admin-users/options', [UserController::class, 'options']);
    Route::get('admin-users/{user}', [UserController::class, 'show']);
    Route::post('admin-users', [UserController::class, 'store'])->middleware('throttle:writes');
    Route::put('admin-users/{user}', [UserController::class, 'update']);
    Route::post('admin-users/{user}/active', [UserController::class, 'toggleActive']);
    Route::post('admin-users/{user}/reset-password', [UserController::class, 'resetPassword']);
    Route::delete('admin-users/{user}/sessions', [UserController::class, 'revokeAllSessions']);
    Route::delete('admin-users/{user}/sessions/{id}', [UserController::class, 'revokeSession']);

    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::post('roles/{role}/copy', [RoleController::class, 'copy']);
    Route::put('roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);
});

Route::middleware('permission:audit.view')->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'index']);
    Route::get('audit-logs/options', [AuditLogController::class, 'options']);
    Route::get('audit-logs/export', [AuditLogController::class, 'export']);
    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
});

Route::middleware('permission:system.health')->group(function () {
    Route::get('system-health', [SystemHealthController::class, 'index']);
    Route::get('system-health/failed-jobs', [SystemHealthController::class, 'failedJobs']);
    Route::post('system-health/failed-jobs/{uuid}/retry', [SystemHealthController::class, 'retryFailedJob']);
    Route::delete('system-health/failed-jobs/{uuid}', [SystemHealthController::class, 'deleteFailedJob']);

    Route::get('backups', [BackupController::class, 'index']);
    Route::post('backups/run', [BackupController::class, 'run'])->middleware('throttle:writes');
    Route::get('backups/{backupRun}/download', [BackupController::class, 'download']);
});
