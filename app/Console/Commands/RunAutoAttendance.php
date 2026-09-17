<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Attendance\AutoAttendanceService;
use App\Support\BranchContext;
use Illuminate\Console\Command;

class RunAutoAttendance extends Command
{
    protected $signature = 'kurs:auto-attendance';

    protected $description = 'Biyometrik girişlerden ders yoklamasını (VAR/GEÇ/GELMEDİ) üretir';

    public function handle(AutoAttendanceService $service, BranchContext $context): int
    {
        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $stats = $context->run($branch->id, fn () => $service->run($branch->id));
            $this->line("{$branch->name}: ".json_encode($stats));
        }

        return self::SUCCESS;
    }
}
