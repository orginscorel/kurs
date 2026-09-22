<?php

use App\Http\Controllers\Api\Portal\PortalController;
use App\Http\Controllers\Api\Portal\PortalExtrasController as X;
use App\Http\Controllers\Api\Portal\PortalPackagesController;
use Illuminate\Support\Facades\Route;

/*
| Öğrenci + veli portalı — YALNIZ oturumdaki öğrencinin / velinin bağlı öğrencisinin verisi.
| Hiçbir uç yol parametresi olarak öğrenci id'si almaz; öğrenci EnsurePortalStudent ('portal.student')
| ile oturumdaki kullanıcıdan çözülür (veli: ?student_id yalnız kendi çocukları arasından).
| Bu dosya routes/api.php'de 'staff' ara katmanının dışında yüklenir.
*/
Route::middleware('portal.student')->prefix('portal')->group(function () {
    Route::get('context', [PortalController::class, 'context']);
    Route::get('summary', [PortalController::class, 'summary']);
    Route::get('schedule', [PortalController::class, 'schedule']);
    Route::get('attendance', [PortalController::class, 'attendance']);
    Route::get('exams', [PortalController::class, 'exams']);
    Route::get('exams/{result}/pdf', [PortalController::class, 'examPdf'])->whereNumber('result')->middleware('throttle:30,1');
    Route::get('homework', [PortalController::class, 'homework']);
    Route::get('finance', [PortalController::class, 'finance']);
    Route::get('finance/overdue-alert', [\App\Http\Controllers\Api\Portal\PortalFinanceController::class, 'overdueAlert']);
    Route::get('finance/statement.pdf', [\App\Http\Controllers\Api\Portal\PortalFinanceController::class, 'statementPdf'])->middleware('throttle:20,1');
    Route::get('packages', [PortalPackagesController::class, 'index']);
    Route::get('guidance', [PortalController::class, 'guidance']);
    Route::get('announcements', [PortalController::class, 'announcements']);
    Route::get('profile', [PortalController::class, 'profile']);

    // Ödev ayrıntısı + teslim (teslim/okundu yalnız öğrencinin kendi hesabı; veli yalnız görür)
    Route::get('homework/{submission}', [X::class, 'homeworkShow'])->whereNumber('submission');
    Route::get('homework/{submission}/files/{document}', [X::class, 'homeworkFile'])->whereNumber(['submission', 'document'])->middleware('throttle:60,1');
    Route::get('progress', [X::class, 'progress']);
    Route::get('study', [X::class, 'study']);
    Route::get('feedback', [X::class, 'feedback']);
    Route::get('teachers', [X::class, 'teachers']);

    Route::middleware('throttle:writes')->group(function () {
        Route::post('homework/{submission}/submit', [X::class, 'homeworkSubmit'])->whereNumber('submission');
        Route::post('homework/{submission}/seen', [X::class, 'homeworkSeen'])->whereNumber('submission');
        Route::delete('homework/{submission}/files/{document}', [X::class, 'homeworkFileDestroy'])->whereNumber(['submission', 'document']);
        Route::post('announcements/{announcement}/read', [X::class, 'announcementRead'])->whereNumber('announcement');
        // Veli → öğretmen mesaj/görüşme talebi (yalnız kayda düşer, mesaj gönderilmez)
        Route::post('requests', [X::class, 'requestStore'])->middleware('throttle:10,1');
        // Paket / koçluk talebi (öğrenci + veli; online ödeme yok, yalnız kayda düşer)
        Route::post('packages/requests', [PortalPackagesController::class, 'store'])->middleware('throttle:10,1');
    });
});
