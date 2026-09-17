<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Homework;
use App\Services\Automation\AutomationEngine;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Son teslim tarihi yarın olan ödevler için öğrencilere hatırlatma: trigger `homework.due_tomorrow`. */
class SendHomeworkDueTomorrowReminders extends Command
{
    protected $signature = 'kurs:homework-due-tomorrow-reminders';

    protected $description = 'Son teslim tarihi yarın olan ödevler için öğrencilere hatırlatma gönderir';

    public function handle(): int
    {
        $tomorrow = CarbonImmutable::tomorrow('Europe/Istanbul');
        $sent = 0;

        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $sent += app(BranchContext::class)->run($branch->id, fn () => $this->runForBranch($tomorrow));
        }

        if ($sent > 0) {
            $this->line("{$sent} öğrenciye ödev hatırlatması gönderildi.");
        }

        return self::SUCCESS;
    }

    private function runForBranch(CarbonImmutable $tomorrow): int
    {
        $homeworks = Homework::query()->with('classGroup.activeStudents')
            ->whereBetween('due_at', [$tomorrow->startOfDay(), $tomorrow->endOfDay()])->get();

        $count = 0;
        foreach ($homeworks as $homework) {
            $students = $homework->classGroup?->activeStudents ?? collect();

            foreach ($students as $student) {
                AutomationEngine::fire('homework.due_tomorrow', $student, [
                    'odev_adi' => $homework->title,
                    'teslim_tarihi' => $homework->due_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '',
                ], [
                    'class_group_id' => $homework->class_group_id,
                    'dedupe_suffix' => "homework:{$homework->id}",
                ]);
                $count++;
            }
        }

        return $count;
    }
}
