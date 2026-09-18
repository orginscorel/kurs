<?php

use App\Http\Controllers\Api\Campaigns\CampaignController;
use App\Http\Controllers\Api\Campaigns\CommunicationReportController;
use App\Http\Controllers\Api\Campaigns\ConsentController;
use App\Http\Controllers\Api\Campaigns\MessagingChannelController;
use Illuminate\Support\Facades\Route;

/*
| Toplu e-posta / SMS gönderimi (kampanya), ileti izinleri, mesaj kanalı ayarları, iletişim raporu.
| messages.campaign: taslak hazırlama + önizleme · messages.campaign_send: onay/gönderme/iptal/yeniden deneme
| messages.consents: ticari ileti onayı ve ret listesi · integrations.sms / integrations.email (ya da integrations.manage): kanal ayarları
*/

// ------------------------------------------------------------ Toplu gönderim
Route::middleware('permission:messages.campaign|messages.campaign_send')->group(function () {
    Route::get('campaigns', [CampaignController::class, 'index']);
    Route::get('campaigns/options', [CampaignController::class, 'options']);
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');
    Route::get('campaigns/{campaign}/recipients', [CampaignController::class, 'recipients'])->whereNumber('campaign');
});

Route::middleware(['permission:messages.campaign', 'throttle:writes'])->group(function () {
    Route::post('campaigns/preview', [CampaignController::class, 'preview']);
    Route::post('campaigns/parse-manual', [CampaignController::class, 'parseManual']);
    Route::post('campaigns', [CampaignController::class, 'store'])->middleware('web.only');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign')->middleware('web.only');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->whereNumber('campaign')->middleware('web.only');
    Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->whereNumber('campaign')->middleware('web.only');
});

Route::middleware(['permission:messages.campaign_send', 'throttle:writes'])->group(function () {
    Route::post('campaigns/{campaign}/approve', [CampaignController::class, 'approve'])->whereNumber('campaign')->middleware('web.only');
    Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->whereNumber('campaign')->middleware('web.only');
    Route::post('campaigns/{campaign}/retry', [CampaignController::class, 'retry'])->whereNumber('campaign')->middleware('web.only');
});

// ------------------------------------------------------------ İleti izinleri (İYS) + ret listesi
Route::middleware('permission:messages.consents')->group(function () {
    Route::get('message-consents', [ConsentController::class, 'index']);
    Route::post('message-consents', [ConsentController::class, 'store'])->middleware('throttle:writes');
    Route::get('message-suppressions', [ConsentController::class, 'suppressions']);
    Route::post('message-suppressions', [ConsentController::class, 'addSuppression'])->middleware('throttle:writes')->middleware('web.only');
    Route::delete('message-suppressions/{suppression}', [ConsentController::class, 'removeSuppression'])->middleware('web.only');
});

// ------------------------------------------------------------ Mesaj kanalları (SMS sağlayıcı, SMTP)
Route::middleware('permission:integrations.manage|integrations.sms|integrations.email')->prefix('messaging-channels')->group(function () {
    Route::get('/', [MessagingChannelController::class, 'index']);
    Route::put('{kind}', [MessagingChannelController::class, 'update'])->whereIn('kind', ['sms', 'email'])->middleware('throttle:writes')->middleware('web.only');
    Route::post('{kind}/test', [MessagingChannelController::class, 'test'])->whereIn('kind', ['sms', 'email'])->middleware('throttle:writes')->middleware('web.only');
    Route::post('{kind}/disable', [MessagingChannelController::class, 'disable'])->whereIn('kind', ['sms', 'email'])->middleware('web.only');
    Route::get('sms/balance', [MessagingChannelController::class, 'balance']);
    Route::get('sms/originators', [MessagingChannelController::class, 'originators']);
    Route::post('email/test-message', [MessagingChannelController::class, 'testMessage'])->middleware('throttle:writes')->middleware('web.only');
});

// ------------------------------------------------------------ Rapor Merkezi › İletişim (reports.view VE messages.view)
Route::middleware(['permission:reports.view', 'permission:messages.view'])->group(function () {
    Route::get('reports/communication', [CommunicationReportController::class, 'show']);
});
