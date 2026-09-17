<?php

use App\Http\Controllers\Api\Academic\ICalFeedController;
use Illuminate\Support\Facades\Route;

/*
| OTURUMSUZ: ders programı iCal akışı (telefonun takvim uygulaması abone olur).
| Kimlik URL'deki gizli jetondur (CalendarFeed, SHA-256 özeti). Bkz. ICalFeedController.
*/
Route::get('calendar/ical/{token}', ICalFeedController::class)
    ->where('token', '[A-Za-z0-9]{32,80}(\.ics)?')
    ->middleware('throttle:60,1');
