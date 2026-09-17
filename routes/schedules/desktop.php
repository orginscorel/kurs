<?php

use Illuminate\Support\Facades\Schedule;

/*
| Masaüstü uygulaması (desktop/docs/DESKTOP.md): GitHub Releases'teki yeni `desktop-v*` sürümünü
| web köküne (/desktop/*) çeker. DESKTOP_GITHUB_TOKEN tanımlı değilse komut hiçbir şey yapmaz.
| Yerel düğümde bu dosya yüklenmez (config/kurs.php › local_schedule_files).
*/
if (config('kurs.node') !== 'local' && config('desktop.github_token')) {
    Schedule::command('kurs:desktop-release-sync')->everyFifteenMinutes()->withoutOverlapping(30)->runInBackground();
}
