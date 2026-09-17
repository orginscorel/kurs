<?php

use Illuminate\Support\Facades\Schedule;

/*
| Zamanlanmış işler. cPanel cron'u her dakika `schedule:run` çalıştırır.
| Kuyruk işçisi de buradan başlatılır (paylaşımlı sunucuda sürekli süreç yok).
| Modüller kendi zamanlamalarını routes/schedules/<modul>.php dosyasına yazar.
*/

Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=30')
    ->everyMinute()->withoutOverlapping(2)->runInBackground();

// Yerel düğümde (KURS_NODE=local) yalnız eşitleme zamanlayıcısı çalışır: mesaj, otomasyon, yedek,
// oturum üretimi vb. yalnız sunucuda (config/kurs.php › local_schedule_files).
$localNode = config('kurs.node') === 'local';
foreach (glob(__DIR__.'/schedules/*.php') as $file) {
    if ($localNode && ! in_array(basename($file), config('kurs.local_schedule_files', []), true)) {
        continue;
    }
    require $file;
}
