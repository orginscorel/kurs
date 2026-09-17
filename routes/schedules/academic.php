<?php

use Illuminate\Support\Facades\Schedule;

// Akademik modül zamanlamaları
Schedule::command('kurs:close-homework')->hourly()->withoutOverlapping();
