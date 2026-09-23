<?php

use App\Http\Controllers\Api\Notifications\NotificationController;
use Illuminate\Support\Facades\Route;

/*
 * Bildirim omurgası: olay×kitle şablonları + "taslak → önizleme (PDF) → onay → gönder" akışı.
 * Gönderim/oluşturma uçları LOCAL tabloları (outbound_messages) yazdığından web.only'dir.
 */

// Katalog ve okuma (görüntüleme yetkisi)
Route::middleware('permission:messages.view')->group(function () {
    Route::get('notifications/catalog', [NotificationController::class, 'catalog']);
    Route::get('notifications/batches', [NotificationController::class, 'index']);
    Route::get('notifications/batches/{batch}', [NotificationController::class, 'show']);
    Route::get('notifications/batches/{batch}/pdf', [NotificationController::class, 'pdf']);
});

// Ayarlar ve şablonlar (şablon yönetim yetkisi)
Route::middleware('permission:templates.manage')->group(function () {
    Route::get('notifications/settings', [NotificationController::class, 'settings']);
    Route::put('notifications/settings/{eventType}', [NotificationController::class, 'updateSetting'])->middleware(['throttle:writes', 'web.only']);
    Route::get('notifications/templates', [NotificationController::class, 'templates']);
    Route::put('notifications/templates/{template}', [NotificationController::class, 'updateTemplate'])->middleware(['throttle:writes', 'web.only']);
    Route::post('notifications/templates/preview', [NotificationController::class, 'previewTemplate']);
});

// Gönderim akışı (gönderim yetkisi)
Route::middleware('permission:messages.send')->group(function () {
    Route::post('notifications/batches', [NotificationController::class, 'store'])->middleware(['throttle:writes', 'web.only']);
    Route::post('notifications/batches/{batch}/approve', [NotificationController::class, 'approve'])->middleware(['throttle:writes', 'web.only']);
    Route::post('notifications/batches/{batch}/cancel', [NotificationController::class, 'cancel'])->middleware('web.only');
    Route::delete('notifications/batches/{batch}', [NotificationController::class, 'destroy'])->middleware('web.only');
});
