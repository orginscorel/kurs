<?php

namespace App\Console\Commands;

use App\Services\Finance\InstallmentMaintenance;
use Illuminate\Console\Command;

class MarkOverdueInstallments extends Command
{
    protected $signature = 'kurs:mark-overdue';

    protected $description = 'Vadesi geçmiş taksitleri GECİKTİ olarak işaretler';

    public function handle(InstallmentMaintenance $maintenance): int
    {
        $count = $maintenance->markOverdue();
        $this->info("{$count} taksit gecikmiş olarak işaretlendi.");

        return self::SUCCESS;
    }
}
