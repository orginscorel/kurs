<?php

namespace App\Console\Commands\Sync;

use App\Sync\RecomputeService;
use Illuminate\Console\Command;

/** Türetilmiş alanlar (bakiye, taksit ödenen tutarı/durumu, stok, katılımcı sayısı): denetle / düzelt. */
class SyncRecompute extends Command
{
    protected $signature = 'kurs:sync-recompute {--fix : sapmaları düzelt (varsayılan: yalnız rapor)} {--only=* : balances|installments|stock|exams}';

    protected $description = 'Eşitlenmeyen sayaç/bakiye alanlarını kaynak satırlardan yeniden hesaplar';

    public function handle(RecomputeService $service): int
    {
        $targets = $this->option('only') ?: RecomputeService::TARGETS;
        $report = $service->run($targets, (bool) $this->option('fix'));
        $rows = [];
        foreach ($report as $t => $r) {
            $rows[] = [$t, $r['checked'], $r['drift'], $r['fixed']];
        }
        $this->table(['Hedef', 'Kontrol', 'Sapma', 'Düzeltilen'], $rows);
        if (! $this->option('fix') && array_sum(array_column($report, 'drift')) > 0) {
            $this->warn('Sapma var; düzeltmek için --fix.');
        }

        return self::SUCCESS;
    }
}
