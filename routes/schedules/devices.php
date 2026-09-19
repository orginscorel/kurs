<?php

use Illuminate\Support\Facades\Schedule;

/*
| Biyometrik terminal köprüsü (docs/CIHAZ-KOPRUSU.md).
|
| Cihaz kurumun yerel ağındadır; web sunucusu ona ULAŞAMAZ. Bu yüzden çekme YALNIZ yerel
| düğümde (Mac masaüstü, KURS_NODE=local) çalışır. Sunucuda hiçbir şey zamanlanmaz.
|
| Komut kendi içinde cihaz başına kilit tutar (Cache::lock), bağlantı hatasını yutar ve
| hatayı cihaz kaydına yazar (zk_last_status / zk_last_error) — zamanlayıcı kırmızıya dönmez.
| withoutOverlapping: internet/cihaz yavaşsa üst üste binmez.
*/
if (config('kurs.node') === 'local') {
    // Yalnız ZKTeco sürücülü, IP'si kayıtlı etkin cihaz varsa çalışır. YT33 (perkotek_fk) / Genel TCP cihazlara
    // arka planda HİÇBİR paket gönderilmez; boş dakikalarda günlük de doldurulmaz.
    Schedule::command('kurs:cihaz-cek')
        ->everyMinute()
        ->when(function (): bool {
            try {
                return \App\Models\Device::query()->withoutGlobalScopes()->where('is_active', true)
                    ->where('protocol', 'zk')->whereNotNull('zk_ip')->whereNull('deleted_at')->exists();
            } catch (\Throwable) {
                return false;
            }
        })
        ->withoutOverlapping(10)
        ->runInBackground()
        ->onFailure(fn () => null);   // cihaz kapalıysa zamanlayıcı sessiz geçer
}
