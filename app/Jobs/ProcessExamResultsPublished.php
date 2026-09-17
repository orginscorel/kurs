<?php

namespace App\Jobs;

use App\Models\AutomationRule;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\NetDrop;
use App\Services\Exams\ReportCardRenderer;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\StaffRecipients;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\BranchContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Sınav sonucu yayımı zinciri (kuyrukta):
 *  1) webhook `exam.completed` (sınav başına bir kez),
 *  2) öğrenci başına webhook `exam.result.created` + trigger `exam.result_published` — aktif bir kural
 *     görsel kart eki istiyorsa ReportCardRenderer ile PNG üretilir (kural yoksa boşuna üretilmez),
 *  3) katılan öğrencilerin risk puanı yeniden hesabı (RecomputeRiskScores işi),
 *  4) bir önceki aynı türdeki sınava göre anlamlı net düşüşü olan öğrenciler → rehber öğretmenine
 *     tek toplu uygulama bildirimi.
 */
class ProcessExamResultsPublished implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $examId, public readonly int $branchId) {}

    public function handle(WebhookDispatcher $webhooks, NotificationService $notifications): void
    {
        app(BranchContext::class)->run($this->branchId, function () use ($webhooks, $notifications) {
            $exam = Exam::query()->find($this->examId);
            if (! $exam || $exam->status !== 'results_published') {
                return;
            }

            $webhooks->dispatch('exam.completed', [
                'exam_id' => $exam->id, 'name' => $exam->name, 'exam_date' => $exam->exam_date?->toDateString(),
                'participant_count' => (int) $exam->participant_count,
            ], $exam->branch_id);

            $needsCard = AutomationRule::query()->where('trigger', 'exam.result_published')->where('is_active', true)->get()
                ->contains(fn (AutomationRule $r) => collect($r->actions)->contains(fn ($a) => ! empty($a['attach_report_card'])));

            $studentIds = [];
            ExamResult::query()->with(['student', 'sections.section.subject'])->where('exam_id', $exam->id)
                ->chunkById(50, function ($results) use ($exam, $needsCard, $webhooks, &$studentIds) {
                    foreach ($results as $result) {
                        if ($result->student) {
                            $studentIds[] = $result->student_id;
                            $this->fireForResult($exam, $result, $needsCard, $webhooks);
                        }
                    }
                });

            if ($studentIds !== []) {
                RecomputeRiskScores::dispatch($studentIds, $this->branchId, "exam:{$exam->id}");
                $this->notifyNetDrops($exam, $studentIds, $notifications);
            }
        });
    }

    private function fireForResult(Exam $exam, ExamResult $result, bool $needsCard, WebhookDispatcher $webhooks): void
    {
        $student = $result->student;

        $reportCardPath = null;
        if ($needsCard) {
            try {
                $reportCardPath = app(ReportCardRenderer::class)->render($result);
            } catch (\Throwable) {
                $reportCardPath = null; // görsel rapor opsiyoneldir; başarısızlık mesajı engellemez
            }
        }

        $sectionLines = $result->sections->map(fn ($s) => sprintf('%s: %s net', $s->section?->name ?? $s->section?->subject?->name ?? '—', number_format((float) $s->net, 2, ',', '.')))->implode("\n");

        $webhooks->dispatch('exam.result.created', [
            'exam_id' => $exam->id, 'exam_result_id' => $result->id, 'student_id' => $student->id, 'net' => (string) $result->net,
            'score' => $result->score !== null ? (string) $result->score : null, 'institution_rank' => $result->institution_rank,
        ], $exam->branch_id);

        AutomationEngine::fire('exam.result_published', $student, [
            'sinav_adi' => $exam->name,
            'toplam_net' => number_format((float) $result->net, 2, ',', '.'),
            'kurum_sirasi' => $result->institution_rank ? (string) $result->institution_rank : '—',
            'ders_netleri' => $sectionLines,
        ], [
            'class_group_id' => $result->class_group_id,
            'report_card_path' => $reportCardPath,
            'dedupe_suffix' => "exam:{$result->id}",
        ]);
    }

    /** @param list<int> $studentIds */
    private function notifyNetDrops(Exam $exam, array $studentIds, NotificationService $notifications): void
    {
        $current = DB::table('exam_results')->where('exam_id', $exam->id)->pluck('net', 'student_id');

        // Aynı sınav türünde, bu sınavdan ÖNCEKİ en son yayımlı sonuç (öğrenci başına).
        $previous = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')
            ->whereIn('r.student_id', $studentIds)->where('e.exam_type_id', $exam->exam_type_id)
            ->where('e.status', 'results_published')->whereNull('e.deleted_at')->where('e.id', '!=', $exam->id)
            ->where(fn ($q) => $q->where('e.exam_date', '<', $exam->exam_date)->orWhere(fn ($q2) => $q2->where('e.exam_date', $exam->exam_date)->where('e.id', '<', $exam->id)))
            ->orderByDesc('e.exam_date')->orderByDesc('e.id')
            ->get(['r.student_id', 'r.net'])->unique('student_id')->pluck('net', 'student_id');

        $byRecipient = [];
        foreach ($current as $studentId => $net) {
            $prev = isset($previous[$studentId]) ? (float) $previous[$studentId] : null;
            if (! NetDrop::isSignificant($prev, (float) $net)) {
                continue;
            }
            $student = Student::query()->find($studentId);
            if (! $student) {
                continue;
            }
            foreach (StaffRecipients::counselorOrManagers($student) as $uid) {
                $byRecipient[$uid][] = sprintf('%s (%s → %s)', $student->full_name, number_format($prev, 2, ',', ''), number_format((float) $net, 2, ',', ''));
            }
        }

        foreach ($byRecipient as $uid => $lines) {
            $count = count($lines);
            $notifications->notify((int) $uid, 'academic', "{$exam->name}: {$count} öğrencide net düşüşü",
                implode("\n", array_slice($lines, 0, 15)).($count > 15 ? "\nve ".($count - 15).' öğrenci daha' : ''),
                '/rehberlik/riskli-ogrenciler',
                ['kind' => 'exam_net_drop', 'exam_id' => $exam->id, 'once' => "exam_net_drop:{$exam->id}"]);
        }
    }
}
