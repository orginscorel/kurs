<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Zamanlayıcı nabzı: her dakika çalışır, "cache" sürücüsüne son çalışma zamanını yazar.
 * Sistem Sağlığı ekranı bu değeri okuyarak schedule:run'ın canlı olup olmadığını gösterir.
 */
class SystemHeartbeat extends Command
{
    protected $signature = 'kurs:heartbeat';

    protected $description = 'Zamanlayıcı sağlık nabzı (system:heartbeat cache anahtarını günceller)';

    public function handle(): int
    {
        Cache::put('system:heartbeat', now()->toIso8601String(), now()->addHours(6));

        return self::SUCCESS;
    }
}
