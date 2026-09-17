<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\Revalidator;
use App\Support\BranchContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gecikmeli otomasyon eylemini çalıştırır (`AutomationRule.delay_minutes`). Çalışma anında
 * durum tekrar denetlenir; artık geçerli değilse (ör. devamsızlık düzeltildi) `skipped` yazılır.
 */
class RunAutomationAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public readonly int $runId,
        public readonly int $branchId,
        public readonly array $vars = [],
        public readonly array $context = [],
    ) {}

    public function handle(): void
    {
        app(BranchContext::class)->run($this->branchId, function () {
            $run = AutomationRun::query()->with('rule')->find($this->runId);

            if (! $run || $run->status !== 'scheduled' || ! $run->rule || ! $run->rule->is_active) {
                return;
            }

            $student = Student::query()->find($run->subject_id);
            if (! $student) {
                $run->forceFill(['status' => 'skipped', 'result' => 'Öğrenci artık bulunamadı.'])->save();

                return;
            }

            if (! Revalidator::stillValid($run->rule->trigger, $student, $this->context)) {
                $run->forceFill(['status' => 'skipped', 'result' => 'Koşul artık geçerli değil (durum değişti).'])->save();

                return;
            }

            AutomationEngine::execute($run, $run->rule, $student, $this->vars, $this->context);
        });
    }
}
