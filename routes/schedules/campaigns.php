<?php

use Illuminate\Support\Facades\Schedule;

// Toplu e-posta / SMS gönderimi: zamanı gelenleri başlat + süren gönderimlere parça işi at (hız sınırı işin içinde).
Schedule::command('kurs:campaigns-run')->everyMinute()->withoutOverlapping(5);
// SMS teslim raporları (gönderildi → iletildi / başarısız)
Schedule::command('kurs:campaigns-sync-reports')->everyTenMinutes()->withoutOverlapping(15);
