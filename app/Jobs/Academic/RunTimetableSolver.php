<?php

namespace App\Jobs\Academic;

use App\Models\TimetableRun;
use App\Services\Academic\Timetable\TimetableService;
use App\Support\BranchContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Program botunu kuyrukta çalıştırır (QUEUE_CONNECTION=database, cron işçisi her dakika). */
class RunTimetableSolver implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public function __construct(public readonly int $runId, public readonly int $branchId) {}

    public function handle(TimetableService $service): void
    {
        app(BranchContext::class)->run($this->branchId, function () use ($service) {
            $run = TimetableRun::query()->find($this->runId);
            if ($run) {
                $service->execute($run);
            }
        });
    }

    public function failed(?\Throwable $e): void
    {
        TimetableRun::query()->withoutGlobalScopes()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'finished_at' => now(),
            'error' => 'Program botu çalışırken beklenmeyen bir sorun oluştu. Ayarları kontrol edip yeniden çalıştırın.',
        ]);
    }
}
