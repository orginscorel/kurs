<?php

namespace App\Console\Commands\Sync;

use App\Sync\Sweeper;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Console\Command;

/**
 * Eşitleme hazırlığı (idempotent): kayıt defterindeki tablolara eksik uuid/updated_at sütunlarını ekler,
 * boş uuid'leri parça parça doldurur, yeni tabloların satır özetini (baseline) alır.
 * Sonradan eklenen tablolar için migration'dan sonra tekrar çalıştırın.
 */
class SyncPrepare extends Command
{
    protected $signature = 'kurs:sync-prepare {--no-backfill : uuid doldurma yapma} {--chunk=500 : parça boyutu} {--rebaseline : tüm tabloların özetini yeniden al}';

    protected $description = 'Eşitleme sütunlarını ekler, uuid doldurur, satır özetlerini alır (idempotent)';

    public function handle(SyncSchema $schema, Sweeper $sweeper): int
    {
        $unknown = SyncRegistry::unknown(SyncSchema::listTables());
        if ($unknown !== []) {
            $this->warn('Kayıt defterinde tanımsız tablolar (app/Sync/SyncRegistry.php): '.implode(', ', $unknown));
        }

        $added = $schema->ensureColumns();
        $this->info($added ? 'Eklenen sütunlar: '.implode(', ', $added) : 'Eksik sütun yok.');

        if (! $this->option('no-backfill')) {
            $t0 = microtime(true);
            $filled = $schema->backfillUuids(max(50, (int) $this->option('chunk')));
            $this->info(sprintf('uuid dolduruldu: %d satır, %d tablo (%.1f sn).', array_sum($filled), count($filled), microtime(true) - $t0));
            $schema->flush();

            $t0 = microtime(true);
            $base = $sweeper->baseline(null, (bool) $this->option('rebaseline'));
            $this->info(sprintf('Satır özeti alınan tablo: %d (%d satır, %.1f sn).', count($base), array_sum($base), microtime(true) - $t0));
        }

        $schema->flush();

        return self::SUCCESS;
    }
}
