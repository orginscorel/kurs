<?php

namespace App\Console\Commands\Sync;

use App\Sync\Local\LocalState;
use App\Sync\Local\LocalSyncEngine;
use Illuminate\Console\Command;

/**
 * Yerel düğüm eşitleme istemcisi. Zamanlayıcı dakikada bir `--loop=55` ile başlatır; döngü
 * sync.interval_seconds aralıkla gönder/çek yapar, hata olursa üstel geri çekilir (durum dosyası).
 */
class SyncRun extends Command
{
    protected $signature = 'kurs:sync
        {--snapshot : ilk kurulum anlık görüntüsünü al}
        {--loop=0 : saniye boyunca döngüde kal (0 = tek tur)}
        {--force : geri çekilmeyi yok say}
        {--status : yalnız durumu göster}
        {--reset-locks : açılış/uyanma: ölü süreçlerden kalan tur kilidini ve zamanlayıcı mutekslerini temizle}
        {--json : çıktıyı JSON ver}';

    protected $description = 'Yerel kurulum ↔ web eşitlemesi (gönder/çek)';

    public function handle(LocalSyncEngine $engine, LocalState $state): int
    {
        if (config('kurs.node') !== 'local') {
            $this->error('Bu komut yalnız yerel düğümde çalışır (KURS_NODE=local).');

            return self::FAILURE;
        }
        if ($this->option('status')) {
            return $this->out($state->summary());
        }
        if ($this->option('reset-locks')) {
            $released = $engine->releaseStaleLock();
            $this->callSilently('schedule:clear-cache');

            return $this->out(['status' => 'ok', 'cycle_lock_released' => $released, 'schedule_mutex_cleared' => true]);
        }
        if ($this->option('snapshot')) {
            $res = $engine->snapshot(fn ($t, $n) => $this->getOutput()->isVerbose() ? $this->line("  $t: $n") : null);

            return $this->out(['snapshot' => $res]);
        }

        $loop = max(0, (int) $this->option('loop'));
        $interval = max(5, (int) config('sync.interval_seconds', 30));
        $wall0 = time();
        $mono0 = hrtime(true);
        $last = null;
        do {
            $started = time();
            $last = $engine->cycle((bool) $this->option('force'));
            if ($loop === 0) {
                break;
            }
            // İstek gelirse (üst çubuktaki "Şimdi eşitle") beklemeden tekrar
            while (! $this->loopExpired($loop, $wall0, $mono0) && time() < $started + $interval) {
                if ($state->get('sync_requested_at')) {
                    break;
                }
                sleep(1);
            }
        } while (! $this->loopExpired($loop, $wall0, $mono0));

        return $this->out($last ?? []);
    }

    /**
     * Döngü süresi doldu mu? Duvar saati VE tekdüze saat birlikte: saat geri alınırsa (ya da uykudan dönünce
     * sıçrarsa) döngü uzamaz; iki saat 60 sn'den fazla ayrıştıysa süreç askıya alınmıştır → hemen bitir,
     * zamanlayıcı temiz bir süreç başlatsın.
     */
    public function loopExpired(int $loop, int $wall0, int|float $mono0, ?int $now = null, int|float|null $monoNow = null): bool
    {
        $wallElapsed = ($now ?? time()) - $wall0;
        $monoElapsed = (int) ((($monoNow ?? hrtime(true)) - $mono0) / 1e9);
        if (abs($wallElapsed - $monoElapsed) > 60) {
            return true;
        }

        return $wallElapsed >= $loop || $monoElapsed >= $loop;
    }

    private function out(array $data): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->line(json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return in_array($data['status'] ?? 'ok', ['error', 'revoked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
