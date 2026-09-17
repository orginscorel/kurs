<?php

use Illuminate\Support\Facades\Schedule;

// Excel içe aktarma: onaylanmayan önizleme dosyaları (kişisel veri) 24 saat sonra silinir
Schedule::call(fn () => app(\App\Services\Imports\ImportService::class)->pruneStale(24))->name('imports:prune-stale')->hourly()->withoutOverlapping();
