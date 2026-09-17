<?php

use App\Http\Controllers\Api\Communication\ProviderWebhookController;
use Illuminate\Support\Facades\Route;

/*
| OTURUMSUZ uçlar: WhatsApp (Meta Cloud API) sağlayıcı webhook'ları.
| Kimlik doğrulaması oturum/izin ile DEĞİL, Meta'nın hub.verify_token (GET) ve
| X-Hub-Signature-256 (POST) doğrulamasıyla yapılır — bkz. ProviderWebhookController.
| Ayrı isim alanı (whatsapp/webhook) kasıtlı: auth altındaki "webhooks" (giden
| webhook aboneliklerimiz) ile karışmasın.
*/
Route::get('whatsapp/webhook', [ProviderWebhookController::class, 'verify']);
Route::post('whatsapp/webhook', [ProviderWebhookController::class, 'statusCallback']);
