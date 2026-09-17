<?php

namespace App\Listeners\Automation;

use App\Events\StudentRiskScoreSaved;
use App\Models\ActivityFeed;
use App\Models\Student;
use App\Models\Task;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\RiskTransition;
use App\Services\Automation\StudentActivityGate;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\StaffRecipients;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\BranchContext;

/**
 * Risk seviyesi yüksek'e GEÇTİ (her gece tekrar değil) → rehber öğretmene "Görüşme planla" görevi +
 * uygulama bildirimi, canlı akış kaydı, webhook `risk.high`, trigger `risk.high`.
 * Aynı öğrenci için açık risk görevi varsa yenisi açılmaz (seviye gidip gelse de tek görev).
 */
class OnRiskScoreSaved
{
    public function handle(StudentRiskScoreSaved $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $score = $event->score;
        if (! RiskTransition::escalatedToHigh($event->previousLevel, $score->level)) {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($score->student_id);
        if (! $student || StudentActivityGate::isInactive($student->status)) {
            return;
        }

        app(BranchContext::class)->run((int) $student->branch_id, fn () => $this->escalate($student, $score->score, $event->previousScore, (array) $score->insights));
    }

    private function escalate(Student $student, int $score, ?int $previousScore, array $insights): void
    {
        $recipients = StaffRecipients::counselorOrManagers($student);
        $counselor = StaffRecipients::counselorUserId($student);
        $highlights = collect($insights)->whereIn('tone', ['danger', 'warning'])->pluck('text')->take(3)->implode(' ');
        $scoreText = $previousScore !== null ? "{$previousScore} → {$score}" : (string) $score;

        $hasOpenTask = Task::query()->where('taskable_type', 'student')->where('taskable_id', $student->id)
            ->whereNull('completed_at')->where('title', 'like', OnGuidanceMeetingRecorded::RISK_TASK_PREFIX.'%')->exists();

        if (! $hasOpenTask) {
            Task::query()->create([
                'branch_id' => $student->branch_id,
                'title' => mb_substr(OnGuidanceMeetingRecorded::RISK_TASK_PREFIX.$student->full_name, 0, 200),
                'description' => trim("Risk puanı yüksek seviyeye çıktı ({$scoreText}). {$highlights}"),
                'taskable_type' => 'student', 'taskable_id' => $student->id,
                'assigned_to' => $counselor ?? ($recipients[0] ?? null),
                'created_by' => null,
                'due_at' => now()->addDays(2)->setTime(10, 0),
                'priority' => 'high',
            ]);
        }

        app(NotificationService::class)->notify($recipients, 'critical', "Yüksek risk: {$student->full_name}",
            trim("Risk puanı {$scoreText}. Görüşme planlayın. {$highlights}"), "/ogrenciler/{$student->id}",
            ['kind' => 'risk_high', 'student_id' => $student->id, 'once' => "risk_high:{$student->id}:".now()->toDateString()]);

        ActivityFeed::query()->create([
            'branch_id' => $student->branch_id, 'kind' => 'risk',
            'message' => mb_substr("{$student->full_name} yüksek risk seviyesine geçti (puan {$score})", 0, 300),
            'student_id' => $student->id, 'subject_type' => 'student', 'subject_id' => $student->id,
            'meta' => ['score' => $score, 'previous_score' => $previousScore], 'occurred_at' => now(),
        ]);

        app(WebhookDispatcher::class)->dispatch('risk.high', [
            'student_id' => $student->id, 'student_no' => $student->student_no, 'score' => $score, 'previous_score' => $previousScore,
        ], (int) $student->branch_id);

        AutomationEngine::fire('risk.high', $student, ['risk_puani' => (string) $score], ['dedupe_suffix' => 'risk_high:'.now()->toDateString()]);
    }
}
