<?php

use App\Http\Controllers\Api\Integrations\IntegrationController;
use App\Http\Controllers\Api\Integrations\WebhookController;
use Illuminate\Support\Facades\Route;

// ------------------------------------------------------------ Entegrasyon merkezi
Route::middleware('permission:integrations.manage')->group(function () {
    Route::get('integrations', [IntegrationController::class, 'index']);
    Route::put('integrations/{kind}', [IntegrationController::class, 'update'])->middleware('throttle:writes')->middleware('web.only');
    Route::post('integrations/{kind}/test', [IntegrationController::class, 'test'])->middleware('throttle:writes')->middleware('web.only');

    // ------------------------------------------------------------ Webhooklar
    Route::get('webhooks', [WebhookController::class, 'index']);
    Route::post('webhooks', [WebhookController::class, 'store'])->middleware('throttle:writes')->middleware('web.only');
    Route::put('webhooks/{webhook}', [WebhookController::class, 'update'])->middleware('throttle:writes')->middleware('web.only');
    Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->middleware('web.only');
    Route::post('webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->middleware('throttle:writes')->middleware('web.only');
    Route::post('webhooks/{webhook}/test', [WebhookController::class, 'sendTest'])->middleware('web.only');
    Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
    Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver'])->middleware('web.only');
});
