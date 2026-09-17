<?php

use App\Http\Controllers\Api\Integrations\IntegrationController;
use App\Http\Controllers\Api\Integrations\WebhookController;
use Illuminate\Support\Facades\Route;

// ------------------------------------------------------------ Entegrasyon merkezi
Route::middleware('permission:integrations.manage')->group(function () {
    Route::get('integrations', [IntegrationController::class, 'index']);
    Route::put('integrations/{kind}', [IntegrationController::class, 'update'])->middleware('throttle:writes');
    Route::post('integrations/{kind}/test', [IntegrationController::class, 'test'])->middleware('throttle:writes');

    // ------------------------------------------------------------ Webhooklar
    Route::get('webhooks', [WebhookController::class, 'index']);
    Route::post('webhooks', [WebhookController::class, 'store'])->middleware('throttle:writes');
    Route::put('webhooks/{webhook}', [WebhookController::class, 'update'])->middleware('throttle:writes');
    Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy']);
    Route::post('webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->middleware('throttle:writes');
    Route::post('webhooks/{webhook}/test', [WebhookController::class, 'sendTest']);
    Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
    Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver']);
});
