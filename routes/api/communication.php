<?php

use App\Http\Controllers\Api\Communication\AnnouncementController;
use App\Http\Controllers\Api\Communication\AutomationController;
use App\Http\Controllers\Api\Communication\MessageController;
use App\Http\Controllers\Api\Communication\PushTokenController;
use App\Http\Controllers\Api\Communication\TemplateController;
use Illuminate\Support\Facades\Route;

// ------------------------------------------------------------ WhatsApp/SMS/E-posta mesajları
Route::middleware('permission:messages.view')->group(function () {
    Route::get('messages', [MessageController::class, 'index']);
});
Route::post('messages/preview', [MessageController::class, 'preview'])->middleware('permission:messages.send');
Route::post('messages/send', [MessageController::class, 'send'])->middleware(['permission:messages.send', 'throttle:writes'])->middleware('web.only');
Route::post('messages/{message}/retry', [MessageController::class, 'retry'])->middleware(['permission:messages.send', 'throttle:writes'])->middleware('web.only');

// ------------------------------------------------------------ Mesaj şablonları
Route::middleware('permission:templates.manage')->group(function () {
    Route::get('message-templates', [TemplateController::class, 'index']);
    Route::post('message-templates', [TemplateController::class, 'store'])->middleware('throttle:writes')->middleware('web.only');
    Route::put('message-templates/{template}', [TemplateController::class, 'update'])->middleware('throttle:writes')->middleware('web.only');
    Route::delete('message-templates/{template}', [TemplateController::class, 'destroy'])->middleware('web.only');
    Route::post('message-templates/preview', [TemplateController::class, 'preview']);
});

// ------------------------------------------------------------ Duyurular
Route::middleware('permission:announcements.manage')->group(function () {
    Route::get('announcements', [AnnouncementController::class, 'index']);
    Route::get('announcements/{announcement}', [AnnouncementController::class, 'show']);
    Route::post('announcements', [AnnouncementController::class, 'store'])->middleware('throttle:writes');
});

// ------------------------------------------------------------ Otomasyon kuralları
Route::middleware('permission:automations.manage')->group(function () {
    Route::get('automations/triggers', [AutomationController::class, 'triggers']);
    Route::post('automations/describe', [AutomationController::class, 'describe']);
    Route::get('automations', [AutomationController::class, 'index']);
    Route::get('automations/recommended', [AutomationController::class, 'recommended']);
    Route::post('automations/recommended/enable', [AutomationController::class, 'enableRecommended'])->middleware('throttle:writes')->middleware('web.only');
    Route::post('automations', [AutomationController::class, 'store'])->middleware('throttle:writes')->middleware('web.only');
    Route::put('automations/{automation}', [AutomationController::class, 'update'])->middleware('throttle:writes')->middleware('web.only');
    Route::post('automations/{automation}/toggle', [AutomationController::class, 'toggle'])->middleware('web.only');
    Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->middleware('web.only');
    Route::get('automations/{automation}/runs', [AutomationController::class, 'runs']);
});

// ------------------------------------------------------------ Push token kaydı (mobil/PWA — her oturum açan kullanıcı)
Route::post('push-tokens', [PushTokenController::class, 'store']);
Route::delete('push-tokens/{token}', [PushTokenController::class, 'destroy']);
