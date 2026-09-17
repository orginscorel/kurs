<?php

namespace App\Console\Commands;

use App\Services\Academic\HomeworkService;
use Illuminate\Console\Command;

class CloseOverdueHomework extends Command
{
    protected $signature = 'kurs:close-homework';

    protected $description = 'Son teslim tarihi geçmiş, teslim edilmemiş ödevleri YAPILMADI olarak kapatır';

    public function handle(HomeworkService $homework): int
    {
        $n = $homework->closeOverdue();
        $this->info("{$n} ödev teslimi YAPILMADI olarak kapatıldı.");

        return self::SUCCESS;
    }
}
