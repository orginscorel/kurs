<?php

use Illuminate\Support\Facades\Schedule;

// Disiplin: süresi biten uzaklaştırma "tamamlandı", düşme tarihi geçen yaptırım "düştü"
Schedule::command('kurs:discipline-sweep')->dailyAt('00:20')->withoutOverlapping();
