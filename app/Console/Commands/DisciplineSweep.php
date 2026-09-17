<?php

namespace App\Console\Commands;

use App\Services\Discipline\DisciplineService;
use Illuminate\Console\Command;

class DisciplineSweep extends Command
{
    protected $signature = 'kurs:discipline-sweep';

    protected $description = 'Süresi biten uzaklaştırmaları tamamlar, düşme tarihi geçen yaptırımları düşürür';

    public function handle(DisciplineService $discipline): int
    {
        $r = $discipline->sweep();
        $this->info("Tamamlanan: {$r['completed']}, düşen: {$r['expired']}");

        return self::SUCCESS;
    }
}
