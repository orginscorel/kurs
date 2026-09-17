<?php

use App\Http\Controllers\Api\Communication\AutomationWiringController;
use Illuminate\Support\Facades\Route;

// Otomasyon olay haritası (tetikleyici kaynağı + son 24 saat) — Otomasyonlar ekranı.
Route::get('automation-wiring', [AutomationWiringController::class, 'index'])->middleware('permission:automations.manage');
