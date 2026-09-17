<?php

use Illuminate\Support\Facades\Schedule;

// "Bugün kuruma gelmedi" tespiti: 07:00-20:00 arası 5 dakikada bir kontrol eder.
Schedule::command('kurs:attendance-no-show')->everyFiveMinutes()->between('07:00', '20:00')->withoutOverlapping(4);
