<?php

use Illuminate\Support\Facades\Schedule;

// CRM + Rehberlik modülü zamanlamaları
Schedule::command('kurs:remind-tasks')->everyFifteenMinutes()->withoutOverlapping();
