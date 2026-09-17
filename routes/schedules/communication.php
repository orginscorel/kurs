<?php

use Illuminate\Support\Facades\Schedule;

// İletişim/otomasyon modülü zamanlamaları.
// Taksit hatırlatmaları: saat/ofset/açık-kapalı kurum ayarından (finance.*). Komut ayar saatinden 21:00'e kadar
// gönderir; 5 dk'da bir çalışması ayar saatinin (ör. 09:30) kaçırılmamasını sağlar, tekrar gönderim dedupe ile engellenir.
Schedule::command('kurs:installment-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('kurs:schedule-tomorrow')->dailyAt('20:00')->withoutOverlapping();
Schedule::command('kurs:lesson-starting-reminders')->everyFiveMinutes()->between('07:00', '22:00')->withoutOverlapping(4);
Schedule::command('kurs:homework-due-tomorrow-reminders')->dailyAt('18:00')->withoutOverlapping();
