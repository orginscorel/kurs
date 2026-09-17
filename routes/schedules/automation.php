<?php

use Illuminate\Support\Facades\Schedule;

// Modüller arası otomasyon bağlantıları (olay haritası: app/Models/AutomationRule::TRIGGER_SOURCES).

// Yönetici günlük özetleri (uygulama bildirimi, dashboard.view yetkililer)
Schedule::command('kurs:daily-digest morning')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('kurs:daily-digest evening')->dailyAt('20:00')->withoutOverlapping();

// Ödev kapanışı (academic: kurs:close-homework saat başı) sonrası: öğrenci/veli + öğretmen kontrol bildirimi
Schedule::command('kurs:homework-missed-notify')->hourlyAt(10)->withoutOverlapping();

// CRM: sonraki aksiyon zamanı gelen adaylar → sorumluya bildirim
Schedule::command('kurs:lead-next-action-reminders')->everyFifteenMinutes()->withoutOverlapping();

// Gece kurs:mark-overdue (00:05) sonrası yeni gecikmeler → webhook payment.overdue
Schedule::command('kurs:overdue-installment-webhooks')->dailyAt('00:20')->withoutOverlapping();
