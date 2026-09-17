<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Branch;
use App\Models\ClassGroup;
use App\Models\LessonSession;
use App\Services\Automation\AutomationEngine;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Her akşam yarınki ders programını öğrencilere gönderir: trigger `schedule.tomorrow`. */
class SendScheduleTomorrow extends Command
{
    protected $signature = 'kurs:schedule-tomorrow';

    protected $description = 'Yarınki ders programını (saat/ders/derslik) öğrencilere WhatsApp ile gönderir';

    public function handle(): int
    {
        $tomorrow = CarbonImmutable::tomorrow('Europe/Istanbul')->toDateString();
        $sent = 0;

        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $sent += app(BranchContext::class)->run($branch->id, function () use ($tomorrow) {
                if (! AutomationRule::query()->where('trigger', 'schedule.tomorrow')->where('is_active', true)->exists()) {
                    return 0;
                }

                return $this->runForBranch($tomorrow);
            });
        }

        $this->line("{$sent} öğrenciye yarınki program gönderildi.");

        return self::SUCCESS;
    }

    private function runForBranch(string $tomorrow): int
    {
        $sessions = LessonSession::query()->with(['subject:id,name', 'classroom:id,name'])
            ->where('date', $tomorrow)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get()
            ->groupBy('class_group_id');

        $count = 0;
        foreach ($sessions as $classGroupId => $group) {
            $lines = $group->map(fn (LessonSession $s) => sprintf(
                '%s %s%s',
                $s->starts_at?->timezone('Europe/Istanbul')->format('H:i'),
                $s->subject?->name ?? '—',
                $s->classroom?->name ? " ({$s->classroom->name})" : '',
            ))->implode("\n");

            $classGroup = ClassGroup::query()->find($classGroupId);
            $students = $classGroup?->activeStudents()->get() ?? collect();

            foreach ($students as $student) {
                AutomationEngine::fire('schedule.tomorrow', $student, ['ders_listesi' => $lines], [
                    'class_group_id' => $classGroupId, 'at_time' => '20:00',
                    'dedupe_suffix' => "schedule:{$tomorrow}",
                ]);
                $count++;
            }
        }

        return $count;
    }
}
