<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Branch;
use App\Models\LessonSession;
use App\Services\Automation\AutomationEngine;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Ders başlamadan X dakika önce öğrencilere hatırlatma: trigger `lesson.starting`.
 * X, kuralın `conditions.minutes_before` alanından okunur (kural bazlı, varsayılan 10).
 */
class SendLessonStartingReminders extends Command
{
    protected $signature = 'kurs:lesson-starting-reminders';

    protected $description = 'Yakında başlayacak dersler için öğrencilere hatırlatma gönderir';

    public function handle(): int
    {
        $now = CarbonImmutable::now('Europe/Istanbul');
        $sent = 0;

        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $sent += app(BranchContext::class)->run($branch->id, fn () => $this->runForBranch($now));
        }

        if ($sent > 0) {
            $this->line("{$sent} ders başlangıcı hatırlatması gönderildi.");
        }

        return self::SUCCESS;
    }

    private function runForBranch(CarbonImmutable $now): int
    {
        $rules = AutomationRule::query()->where('trigger', 'lesson.starting')->where('is_active', true)->get();
        $count = 0;

        foreach ($rules as $rule) {
            $minutesBefore = (int) ($rule->conditions['minutes_before'] ?? 10);
            $target = $now->addMinutes($minutesBefore);

            $sessions = LessonSession::query()->with(['classGroup.activeStudents', 'subject:id,name', 'classroom:id,name'])
                ->where('date', $now->toDateString())->where('status', '!=', 'cancelled')
                ->whereBetween('starts_at', [$target->subMinutes(2), $target->addMinutes(2)])
                ->get();

            foreach ($sessions as $session) {
                $students = $session->classGroup?->activeStudents ?? collect();

                foreach ($students as $student) {
                    AutomationEngine::fire('lesson.starting', $student, [
                        'ders_adi' => $session->subject?->name ?? '', 'dakika' => (string) $minutesBefore,
                        'derslik' => $session->classroom?->name ?? '',
                    ], [
                        'class_group_id' => $session->class_group_id, 'minutes_before' => $minutesBefore,
                        'dedupe_suffix' => "lesson_start:{$session->id}",
                    ]);
                    $count++;
                }
            }
        }

        return $count;
    }
}
