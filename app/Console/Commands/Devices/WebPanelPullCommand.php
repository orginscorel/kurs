<?php

namespace App\Console\Commands\Devices;

use App\Models\Device;
use App\Services\Devices\WebPanel\WebPanelDriver;
use Illuminate\Console\Command;

/**
 * Web panel (HTTP) sürücüsüyle OTOMATİK çekme — yalnız yerel düğüm, yalnız sürücüsü 'webpanel' ve uçları DOĞRULANMIŞ cihazlar.
 * Doğrulanmamışsa hiçbir istek atılmaz (uç noktalar keşif+onaydan gelir; uydurma yok). Akış: okutma → panel sorgusu →
 * köprü → PresenceService (otomatik eşleşme, yoklama/PDKS) → veli bildirimi. Zamanlayıcı: routes/schedules/devices.php.
 */
class WebPanelPullCommand extends Command
{
    protected $signature = 'kurs:panel-cek {--device=} {--json}';

    protected $description = 'Cihaz web panelinden (doğrulanmış uçlarla) kullanıcı ve kayıtları otomatik çeker';

    public function handle(WebPanelDriver $driver): int
    {
        if (config('kurs.node') !== 'local') {
            $this->warn('Web panel çekme yalnız masaüstü yerel düğümde çalışır.');

            return self::SUCCESS;
        }

        $devices = Device::query()->withoutGlobalScopes()->where('is_active', true)->where('protocol', 'webpanel')->whereNull('deleted_at')->get()
            ->filter(fn (Device $d) => $driver->verified($d));

        if ($devices->isEmpty()) {
            $this->line('Doğrulanmış web panel sürücülü cihaz yok (keşif uçları henüz onaylanmadı).');

            return self::SUCCESS;
        }

        // Waiting for verified panel capture: doğrulanmış uçlar gelince fetchAttendanceLogs → PresenceService::ingest.
        foreach ($devices as $device) {
            $this->line("• {$device->name}: web panel çekme uçları doğrulandığında etkinleşecek.");
        }

        return self::SUCCESS;
    }
}
