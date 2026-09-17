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
        if ($this->option('snapshot')) {
            $res = $engine->snapshot(fn ($t, $n) => $this->getOutput()->isVerbose() ? $this->line("  $t: $n") : null);

            return $this->out(['snapshot' => $res]);
        }

        $until = time() + max(0, (int) $this->option('loop'));
        $interval = max(5, (int) config('sync.interval_seconds', 30));
        $last = null;
        do {
            $started = time();
            $last = $engine->cycle((bool) $this->option('force'));
            if ((int) $this->option('loop') === 0) {
                break;
            }
            // İstek gelirse (üst çubuktaki "Şimdi eşitle") beklemeden tekrar
            while (time() < min($until, $started + $interval)) {
                if ($state->get('sync_requested_at')) {
                    break;
                }
                sleep(1);
            }
        } while (time() < $until);

        return $this->out($last ?? []);
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
