<?php

namespace App\Listeners\Communication;

use App\Events\ExamResultsPublished;
use App\Jobs\ProcessExamResultsPublished;
use App\Models\Exam;

/**
 * Sınav sonucu yayımlandı → tüm zincir kuyrukta çalışır (yayımla isteği görsel kart üretimini beklemez):
 * webhook `exam.completed` + öğrenci başına `exam.result.created`, trigger `exam.result_published`
 * (görsel sonuç kartı eki), ardından risk yeniden hesabı ve net düşüşü bildirimleri. Bkz. ProcessExamResultsPublished.
 */
class AutomateOnExamResultsPublished
{
    public function handle(ExamResultsPublished $event): void
    {
        if (config('kurs.silent_events')) {
            return; // sessiz mod dispatch anında denetlenir (işçi süreci demo bayrağını görmez)
        }

        $exam = Exam::query()->withoutGlobalScope('branch')->find($event->examId);
        if (! $exam) {
            return;
        }

        ProcessExamResultsPublished::dispatch($exam->id, (int) $exam->branch_id);
    }
}
