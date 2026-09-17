<?php

use App\Http\Controllers\Api\Discipline\BoardController;
use App\Http\Controllers\Api\Discipline\CatalogController;
use App\Http\Controllers\Api\Discipline\DefenseController;
use App\Http\Controllers\Api\Discipline\DisciplineController;
use App\Http\Controllers\Api\Discipline\DisciplinePortalController;
use App\Http\Controllers\Api\Discipline\DisciplineTeacherController;
use App\Http\Controllers\Api\Discipline\IncidentController;
use App\Http\Controllers\Api\Discipline\SanctionController;
use Illuminate\Support\Facades\Route;

/*
| Disiplin ve cezai işlem sistemi. Bu dosya routes/api.php'de 'staff' ara katmanıyla sarılır (yönetim uçları).
| Yetkiler: discipline.view / create / decide / board / settings / export (app/Support/Permissions.php).
| Kurul kademesindeki yaptırım ve itirazların yetkisi ayrıca servis katmanında denetlenir (DisciplineRules).
|
| Portal uçları (dosyanın sonunda) 'staff'ı withoutMiddleware ile DIŞARIDA bırakır ve kendi portal
| ara katmanını kullanır: öğrenci/veli → portal.student, öğretmen → portal.teacher (kapsam: kendi sınıfları).
*/
Route::middleware('permission:discipline.view')->prefix('discipline')->whereNumber(['incident', 'defense', 'sanction', 'appeal', 'meeting', 'item', 'behavior', 'type', 'student', 'document'])->group(function () {
    Route::get('overview', [DisciplineController::class, 'overview']);
    Route::get('options', [DisciplineController::class, 'options']);
    Route::get('attention', [DisciplineController::class, 'attention']);
    Route::get('settings', [DisciplineController::class, 'settings']);
    Route::get('students/{student}', [DisciplineController::class, 'student']);

    Route::get('incidents', [IncidentController::class, 'index']);
    Route::get('incidents/{incident}', [IncidentController::class, 'show']);
    Route::get('incidents/{incident}/documents/{document}', [IncidentController::class, 'download'])->middleware('throttle:60,1');
    Route::get('incidents/{incident}/notify-draft', [IncidentController::class, 'notifyDraft']);

    Route::get('defenses', [DefenseController::class, 'index']);
    Route::get('defenses/{defense}/pdf', [DefenseController::class, 'pdf'])->middleware('throttle:30,1');
    Route::get('sanctions', [SanctionController::class, 'index']);

    Route::get('board', [BoardController::class, 'index']);
    Route::get('board/candidates', [BoardController::class, 'candidates']);
    Route::get('board/incident-search', [BoardController::class, 'incidentSearch']);
    Route::get('board/{meeting}', [BoardController::class, 'show']);
    Route::get('board/{meeting}/pdf', [BoardController::class, 'pdf'])->middleware('throttle:30,1');

    Route::middleware('throttle:writes')->group(function () {
        Route::middleware('permission:discipline.create')->group(function () {
            Route::post('incidents', [IncidentController::class, 'store']);
            Route::put('incidents/{incident}', [IncidentController::class, 'update']);
            Route::post('incidents/{incident}/status', [IncidentController::class, 'status']);
            Route::post('incidents/{incident}/documents', [IncidentController::class, 'upload']);
            Route::delete('incidents/{incident}/documents/{document}', [IncidentController::class, 'removeDocument']);
            Route::post('incidents/{incident}/notified', [IncidentController::class, 'notified']);
            Route::post('incidents/{incident}/defenses', [DefenseController::class, 'store']);
            Route::post('incidents/{incident}/defenses/waive', [DefenseController::class, 'waive']);
            Route::post('defenses/{defense}/record', [DefenseController::class, 'record']);
            Route::post('sanctions/{sanction}/appeal', [SanctionController::class, 'appeal']);
        });

        Route::middleware('permission:discipline.decide')->group(function () {
            Route::post('incidents/{incident}/sanctions', [SanctionController::class, 'store']);
            Route::post('incidents/{incident}/points', [IncidentController::class, 'points']);
            Route::delete('incidents/{incident}', [IncidentController::class, 'destroy']);
            Route::post('sanctions/{sanction}/status', [SanctionController::class, 'status']);
        });

        // İtiraz kararı: decide ya da board; hangisinin gerektiği yaptırım kademesine göre serviste denetlenir
        Route::post('appeals/{appeal}/decide', [SanctionController::class, 'decideAppeal'])->middleware('permission:discipline.decide|discipline.board');

        Route::middleware('permission:discipline.board')->group(function () {
            Route::post('board', [BoardController::class, 'store']);
            Route::put('board/{meeting}', [BoardController::class, 'update']);
            Route::post('board/{meeting}/status', [BoardController::class, 'status']);
            Route::post('board/{meeting}/items', [BoardController::class, 'addItem']);
            Route::delete('board/{meeting}/items/{item}', [BoardController::class, 'removeItem']);
            Route::post('board/{meeting}/items/{item}/decide', [BoardController::class, 'decide']);
        });

        Route::middleware('permission:discipline.settings')->group(function () {
            Route::put('settings', [DisciplineController::class, 'updateSettings']);
            Route::post('behaviors', [CatalogController::class, 'storeBehavior']);
            Route::put('behaviors/{behavior}', [CatalogController::class, 'updateBehavior']);
            Route::delete('behaviors/{behavior}', [CatalogController::class, 'destroyBehavior']);
            Route::put('sanction-types/{type}', [CatalogController::class, 'updateType']);
        });
    });
});

/* ---------------------------------------------------------------- Portallar (staff DIŞI) */

// Öğrenci / veli portalı: yalnız sonuçlanmış yaptırımlar + savunma istemi (kurum ayarıyla)
Route::withoutMiddleware('staff')->middleware('portal.student')->prefix('portal/discipline')->group(function () {
    Route::get('/', [DisciplinePortalController::class, 'index']);
    Route::post('defenses/{defense}', [DisciplinePortalController::class, 'submitDefense'])->whereNumber('defense')->middleware('throttle:10,1');
});

// Öğretmen portalı: "Olay bildir" (ön yüz öğretmen portalı modülünde)
Route::withoutMiddleware('staff')->middleware('portal.teacher')->prefix('teacher-portal/discipline')->group(function () {
    Route::get('options', [DisciplineTeacherController::class, 'options']);
    Route::get('incidents', [DisciplineTeacherController::class, 'index']);
    Route::post('incidents', [DisciplineTeacherController::class, 'store'])->middleware('throttle:20,1');
});
