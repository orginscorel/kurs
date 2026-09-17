<?php

namespace App\Jobs;

use App\Services\System\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Manuel/otomatik yedekleme kuyruk işi (paylaşımlı sunucuda büyük dump'lar isteği bloklamasın diye). */
class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 590;

    public function __construct(public readonly string $kind) {}

    public function handle(BackupService $backups): void
    {
        try {
            $backups->run($this->kind);
        } catch (\Throwable) {
            // BackupService zaten BackupRun kaydını 'failed' işaretler ve denetim kaydı yazar.
        }
    }
}
