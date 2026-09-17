<?php

namespace App\Console\Commands\Desktop;

use App\Sync\Local\LocalState;
use App\Sync\Local\LocalSyncEngine;
use App\Sync\Local\SyncClient;
use App\Sync\Local\SyncHttpException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Masaüstü kurulum sihirbazının ilk eşitleme adımı; ilerlemeyi satır satır JSON verir:
 *   {"event":"start","tables":N,"rows":M}
 *   {"event":"progress","table":"students","rows":120,"done":340,"total":5000,"percent":6.8}
 *   {"event":"done","tables":…,"rows":…,"cursor":…,"deferred":…,"ms":…}
 *   {"event":"error","code":"offline|server|unpaired|unexpected","message":"…"}
 * Cihaz jetonu SYNC_DEVICE_TOKEN ortam değişkeninden gelir (anahtar zinciri).
 */
class DesktopSnapshot extends Command
{
    protected $signature = 'kurs:desktop-snapshot';

    protected $description = 'Masaüstü uygulaması: ilk anlık görüntüyü ilerleme olaylarıyla alır (makine arayüzü)';

    protected $hidden = true;

    public function handle(LocalSyncEngine $engine, SyncClient $client, LocalState $state): int
    {
        if (config('kurs.node') !== 'local') {
            return $this->emit(['event' => 'error', 'code' => 'not_local', 'message' => 'Bu komut yalnız yerel düğümde çalışır.'], self::FAILURE);
        }
        if (! $client->isPaired()) {
            return $this->emit(['event' => 'error', 'code' => 'unpaired', 'message' => 'Bu kurulum henüz eşleştirilmemiş.'], self::FAILURE);
        }

        try {
            // Yüzde için toplam satır (motor kendi listesini ayrıca ister; sayılar arada az değişebilir)
            $manifest = $client->manifest();
            $total = max(1, (int) array_sum(array_column($manifest['tables'] ?? [], 'rows')));
            $this->emit(['event' => 'start', 'tables' => count($manifest['tables'] ?? []), 'rows' => $total]);

            $done = 0;
            $res = $engine->snapshot(function (string $table, int $rows) use (&$done, $total) {
                $done += $rows;
                $this->emit([
                    'event' => 'progress', 'table' => $table, 'rows' => $rows, 'done' => $done, 'total' => $total,
                    'percent' => round(min(99.0, $done / $total * 100), 1),
                ]);
            });
            $state->writeFile(['phase' => 'idle', 'last_error' => null]);

            return $this->emit(['event' => 'done'] + $res);
        } catch (SyncHttpException $e) {
            return $this->emit(['event' => 'error', 'code' => $e->isOffline() ? 'offline' : ($e->isRevoked() ? 'revoked' : 'server'), 'message' => $e->getMessage()], self::FAILURE);
        } catch (\Throwable $e) {
            report($e);

            return $this->emit(['event' => 'error', 'code' => 'unexpected', 'message' => 'İlk eşitleme tamamlanamadı: '.$e->getMessage()], self::FAILURE);
        }
    }

    /** @param array<string, mixed> $data */
    private function emit(array $data, ?int $code = null): int
    {
        $this->output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return $code ?? self::SUCCESS;
    }
}
