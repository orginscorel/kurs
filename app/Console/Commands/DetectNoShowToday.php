<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Attendance\NoShowDetectionService;
use App\Support\BranchContext;
use Illuminate\Console\Command;

class DetectNoShowToday extends Command
{
    protected $signature = 'kurs:attendance-no-show';

    protected $description = 'İlk dersinden belirli süre sonra kuruma girişi olmayan öğrencileri tespit eder (activity_feed + StudentNoShowToday)';

    public function handle(NoShowDetectionService $service, BranchContext $context): int
    {
        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $count = $context->run($branch->id, fn () => $service->run($branch->id));
            if ($count > 0) {
                $this->line("{$branch->name}: {$count} öğrenci gelmedi olarak işaretlendi.");
            }
        }

        return self::SUCCESS;
    }
}
