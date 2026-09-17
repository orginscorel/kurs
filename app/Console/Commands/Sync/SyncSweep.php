<?php

namespace App\Console\Commands\Sync;

use App\Sync\Sweeper;
use Illuminate\Console\Command;

/** DB::table / toplu yazmaları değişiklik günlüğüne alır (hızlı kip: parmak izi değişen tablolar). */
class SyncSweep extends Command
{
    protected $signature = 'kurs:sync-sweep {--full : tüm tabloları satır satır karşılaştır} {--table=* : yalnız bu tablolar}';

    protected $description = 'Eşitleme süpürücüsü: olaysız yazmaları günlüğe yazar';

    public function handle(Sweeper $sweeper): int
    {
        $only = $this->option('table') ?: null;
        $s = $sweeper->sweep($only, (bool) $this->option('full'));
        if ($s['inserted'] + $s['updated'] + $s['deleted'] > 0 || $this->getOutput()->isVerbose()) {
            $this->line(sprintf('Süpürme: %d tablo tarandı, %d atlandı; +%d ~%d -%d (%d ms)',
                $s['tables'], $s['skipped'], $s['inserted'], $s['updated'], $s['deleted'], $s['ms']));
        }

        return self::SUCCESS;
    }
}
