<?php

namespace App\Console\Commands;

use App\Services\System\BackupService;
use Illuminate\Console\Command;

/**
 * Veritabanı yedeği alır: mysqldump + gzip + SHA-256, storage/app/private/backups/.
 * Zamanlama: routes/schedules/system.php (günlük 03:00, haftalık pazar 03:30).
 */
class RunBackup extends Command
{
    protected $signature = 'kurs:backup {--kind=manual : daily|weekly|manual}';

    protected $description = 'Veritabanının sıkıştırılmış yedeğini alır ve saklama politikasına göre eskilerini temizler';

    public function handle(BackupService $backups): int
    {
        $kind = $this->option('kind');
        if (! in_array($kind, ['daily', 'weekly', 'manual'], true)) {
            $this->error('Geçersiz --kind. daily, weekly ya da manual olmalı.');

            return self::FAILURE;
        }

        try {
            $run = $backups->run($kind);
            $this->info("Yedek alındı: {$run->path} ({$run->size} bayt, sha256:{$run->checksum}).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Yedekleme başarısız: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
