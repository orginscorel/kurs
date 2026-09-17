<?php

use App\Http\Controllers\Api\Academic\CalendarController;
use App\Http\Controllers\Api\Academic\HolidayController;
use App\Http\Controllers\Api\Academic\ScheduleController;
use App\Http\Controllers\Api\Academic\SessionToolsController;
use App\Http\Controllers\Api\Academic\TimeTemplateController;
use App\Http\Controllers\Api\Academic\TimetableController;
use Illuminate\Support\Facades\Route;

/*
| Ders programı sistemi: zaman şablonları, program botu, yedek öğretmen, telafi, tatiller,
| birleşik takvim, haftalık PDF, iCal bağlantısı. (Oturumsuz iCal akışı: routes/api-public/academic-calendar.php)
*/

Route::middleware('permission:schedule.view')->group(function () {
    Route::get('calendar/events', [CalendarController::class, 'events']);
    Route::get('schedule/pdf', [CalendarController::class, 'pdf']);
    Route::get('calendar/feeds', [CalendarController::class, 'feedStatus']);
    Route::post('calendar/feeds', [CalendarController::class, 'createFeed'])->middleware('throttle:writes');

    Route::get('holidays', [HolidayController::class, 'index']);
    Route::get('time-templates', [TimeTemplateController::class, 'index']);

    Route::get('timetable/options', [TimetableController::class, 'options']);
    Route::get('timetable/runs', [TimetableController::class, 'index']);
    Route::get('timetable/runs/{run}', [TimetableController::class, 'show']);

    Route::get('schedule/sessions/{session}/substitutes', [SessionToolsController::class, 'substitutes']);
    Route::get('schedule/sessions/{session}/free-slots', [SessionToolsController::class, 'freeSlots']);
    Route::get('timetable/leave-impact', [SessionToolsController::class, 'leaveImpact']);
});

Route::middleware(['permission:schedule.manage', 'throttle:writes'])->group(function () {
    Route::post('calendar/feeds/revoke', [CalendarController::class, 'revokeFeeds']);

    Route::post('holidays', [HolidayController::class, 'store']);
    Route::put('holidays/{holiday}', [HolidayController::class, 'update']);
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy']);

    Route::post('time-templates', [TimeTemplateController::class, 'store']);
    Route::post('time-templates/auto-assign', [TimeTemplateController::class, 'autoAssign']);
    Route::put('time-templates/{template}', [TimeTemplateController::class, 'update']);
    Route::delete('time-templates/{template}', [TimeTemplateController::class, 'destroy']);

    Route::post('timetable/runs', [TimetableController::class, 'store']);
    Route::put('timetable/teachers', [TimetableController::class, 'updateTeachers']);
    Route::post('timetable/runs/{run}/apply', [TimetableController::class, 'apply']);
    Route::post('timetable/runs/{run}/rollback', [TimetableController::class, 'rollback']);
    Route::post('timetable/runs/{run}/discard', [TimetableController::class, 'discard']);

    Route::put('schedule/{schedule}/lock', [ScheduleController::class, 'lock']);
    Route::post('schedule/sessions/{session}/substitute', [SessionToolsController::class, 'substitute']);
    Route::post('schedule/sessions/{session}/move', [SessionToolsController::class, 'move']);
});
