<?php

namespace App\Jobs;

use App\Models\OpticalImport;
use App\Services\Exams\Optical\OpticalImportService;
use App\Support\BranchContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Büyük optik dosyalarını kuyrukta işler (QUEUE_CONNECTION=database, cron işçisi). */
class ProcessOpticalImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $importId, public readonly int $branchId) {}

    public function handle(OpticalImportService $service): void
    {
        app(BranchContext::class)->run($this->branchId, function () use ($service) {
            $import = OpticalImport::query()->find($this->importId);
            if (! $import || $import->status === 'completed') {
                return;
            }
            $service->process($import);
        });
    }

    public function failed(?\Throwable $e): void
    {
        OpticalImport::query()->whereKey($this->importId)->update([
            'status' => 'failed', 'finished_at' => now(),
            'error' => 'İçe aktarma kuyruk işinde başarısız oldu. Dosyayı yeniden yükleyin.',
        ]);
    }
}
