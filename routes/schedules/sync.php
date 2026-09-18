<?php

use Illuminate\Support\Facades\Schedule;

/*
| Eşitleme (docs/SYNC.md).
| Sunucu: DB::table ile yapılan olaysız yazmaları günlüğe alan süpürücü (hızlı kip dakikada bir, tam kip 30 dk'da bir),
|         eski günlük/alındı budaması (gece).
| Yerel düğüm: kurs:sync döngüsü (gönder/çek, geri çekilme) dakikada bir; komut kendi içinde aralığı yönetir.
*/
if (config('kurs.node') === 'local') {
    // Masaüstü paketi (KURS_SYNC_DRIVER=desktop) eşitleme turlarını KENDİSİ yönetir (src-tauri/src/runtime.rs › sync_worker):
    // runInBackground'lı olayın muteksi ancak schedule:finish ile silinir; uygulama kapanınca/uyuyunca zincir ölür,
    // muteks 10 dk kalır ve eşitleme sessizce atlanır (1.12.0 olayı). Masaüstü dışı yerel düğümde eski döngü sürer.
    if (env('KURS_SYNC_DRIVER') !== 'desktop') {
        Schedule::command('kurs:sync --loop=55')->everyMinute()->withoutOverlapping(2)->runInBackground();
    }
} else {
    Schedule::command('kurs:sync-sweep')->everyMinute()->withoutOverlapping(5);
    Schedule::command('kurs:sync-sweep --full')->everyThirtyMinutes()->withoutOverlapping(15);
    Schedule::command('kurs:sync-prune')->dailyAt('04:10')->withoutOverlapping();
    // Dosya dizini (sha256): cihazların indireceği/yükleyeceği fotoğraf ve belgeler
    Schedule::command('kurs:sync-files --index')->everyTenMinutes()->withoutOverlapping(10);
}
