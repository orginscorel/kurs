<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Student;
use App\Services\Students\StudentInsights;
use App\Support\BranchContext;
use Illuminate\Console\Command;

class ComputeRiskScores extends Command
{
    protected $signature = 'kurs:compute-risk';

    protected $description = 'Aktif öğrencilerin risk puanlarını ve akıllı analizlerini yeniden hesaplar';

    public function handle(StudentInsights $insights, BranchContext $context): int
    {
        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $count = $context->run($branch->id, function () use ($insights) {
                $n = 0;
                Student::query()->whereIn('status', ['active', 'enrolled', 'frozen'])->with('riskScore')
                    ->chunkById(200, function ($students) use ($insights, &$n) {
                        foreach ($students as $student) {
                            $insights->riskFor($student, refresh: true);
                            $n++;
                        }
                    });

                return $n;
            });
            $this->info("{$branch->name}: {$count} öğrenci analiz edildi.");
        }

        return self::SUCCESS;
    }
}
