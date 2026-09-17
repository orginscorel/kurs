<?php

use App\Support\Settings;
use Illuminate\Support\Facades\Schedule;

// Öğretmen/Personel + Ayarlar modülü zamanlamaları

// Zamanlayıcı nabzı: Sistem Sağlığı ekranı bunun yakınlığına bakar.
Schedule::command('kurs:heartbeat')->everyMinute()->withoutOverlapping();

// Veritabanı yedeği: günlük 03:00 (Ayarlar > Yedekleme "günlük yedek" kapalıysa atlanır), haftalık pazar 03:30.
Schedule::command('kurs:backup --kind=daily')->dailyAt('03:00')->withoutOverlapping(60)
    ->when(fn () => filter_var(Settings::get('backup.daily_enabled', true), FILTER_VALIDATE_BOOLEAN));
Schedule::command('kurs:backup --kind=weekly')->weeklyOn(0, '03:30')->withoutOverlapping(60);
