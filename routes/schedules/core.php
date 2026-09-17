<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('kurs:mark-overdue')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('kurs:generate-sessions --days=21')->dailyAt('00:30')->withoutOverlapping();
Schedule::command('kurs:auto-attendance')->everyFiveMinutes()->between('07:00', '23:00')->withoutOverlapping(10);
Schedule::command('kurs:compute-risk')->dailyAt('02:15')->withoutOverlapping(120);
Schedule::command('kurs:prune')->dailyAt('03:30')->withoutOverlapping();
// KVKK: ayrılan öğrencinin kişisel verisi retention.withdrawn_student_anonymize_after_days sonra anonimleştirilir.
Schedule::command('kurs:anonymize-withdrawn')->dailyAt('03:45')->withoutOverlapping(30);
