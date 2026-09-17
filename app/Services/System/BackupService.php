<?php

namespace App\Services\System;

use App\Models\BackupRun;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Veritabanı yedeği: mysqldump + gzip, storage/app/private/backups/.
 * Kimlik bilgileri komut satırına değil, 0600 izinli geçici --defaults-extra-file'a yazılır.
 */
class BackupService
{
    public const DIR = 'backups';

    public function run(string $kind): BackupRun
    {
        $run = BackupRun::query()->create(['kind' => $kind, 'status' => 'running', 'started_at' => now()]);

        try {
            $path = $this->dump($kind);
            $full = Storage::disk('local')->path($path);
            $checksum = hash_file('sha256', $full);
            $size = filesize($full);

            $run->forceFill([
                'status' => 'success', 'path' => $path, 'size' => $size, 'checksum' => $checksum, 'finished_at' => now(),
            ])->save();

            Audit::log('backup.completed', sprintf('%s yedeğini aldı (%s, %s).', $this->kindLabel($kind), $this->humanSize($size), mb_substr($checksum, 0, 12)));
            $this->prune($kind);
        } catch (\Throwable $e) {
            $run->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()])->save();
            Audit::log('backup.failed', sprintf('%s yedeği alınamadı: %s', $this->kindLabel($kind), mb_substr($e->getMessage(), 0, 200)));
            throw $e;
        }

        return $run->fresh();
    }

    private function dump(string $kind): string
    {
        $db = config('database.connections.mysql');
        $credsFile = tempnam(sys_get_temp_dir(), 'kurs_db_');
        chmod($credsFile, 0600);
        file_put_contents($credsFile, sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
            $db['username'], $db['password'], $db['host'] ?? '127.0.0.1', $db['port'] ?? 3306,
        ));

        $relativePath = self::DIR.'/'.$kind.'-'.now()->format('Y-m-d_His').'.sql.gz';
        Storage::disk('local')->makeDirectory(self::DIR);
        $fullPath = Storage::disk('local')->path($relativePath);

        try {
            $socket = $db['unix_socket'] ?? null;
            $args = ['mysqldump', '--defaults-extra-file='.$credsFile, '--single-transaction', '--quick', '--routines', '--skip-lock-tables'];
            if ($socket) {
                $args[] = '--socket='.$socket;
            }
            $args[] = $db['database'];

            $process = new Process($args);
            $process->setTimeout(600);
            $gz = gzopen($fullPath, 'wb9');
            if (! $gz) {
                throw new \RuntimeException('Yedek dosyası oluşturulamadı.');
            }

            $process->run(function ($type, $buffer) use ($gz) {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                }
            });
            gzclose($gz);

            if (! $process->isSuccessful()) {
                @unlink($fullPath);
                throw new \RuntimeException('mysqldump başarısız: '.mb_substr($process->getErrorOutput(), 0, 500));
            }
            if (! file_exists($fullPath) || filesize($fullPath) < 100) {
                throw new \RuntimeException('Yedek dosyası boş görünüyor.');
            }
        } finally {
            @unlink($credsFile);
        }

        return $relativePath;
    }

    private function prune(string $kind): void
    {
        if (! in_array($kind, ['daily', 'weekly'], true)) {
            return;
        }
        $keep = (int) Settings::get('backup.'.$kind.'_keep', $kind === 'daily' ? 14 : 8);

        $orderedIds = BackupRun::query()->where('kind', $kind)->where('status', 'success')
            ->orderByDesc('finished_at')->pluck('id')->all();

        $pruneIds = self::idsToPrune($orderedIds, $keep);
        if ($pruneIds === []) {
            return;
        }

        $old = BackupRun::query()->whereIn('id', $pruneIds)->get();
        foreach ($old as $run) {
            if ($run->path) {
                Storage::disk('local')->delete($run->path);
            }
            $run->delete();
        }
    }

    /**
     * Saklama rotasyonu: en yeniden en eskiye sıralı id listesinde ilk $keep tanesi korunur,
     * kalanı silinecekler olarak döner. DB'den bağımsız, test edilebilir.
     *
     * @param  list<int>  $orderedIdsNewestFirst
     * @return list<int>
     */
    public static function idsToPrune(array $orderedIdsNewestFirst, int $keep): array
    {
        if ($keep < 0) {
            $keep = 0;
        }

        return array_values(array_slice($orderedIdsNewestFirst, $keep));
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'daily' => 'Günlük', 'weekly' => 'Haftalık', 'manual' => 'Manuel', default => $kind,
        };
    }

    private function humanSize(int $bytes): string
    {
        $mb = $bytes / 1024 / 1024;

        return $mb >= 1 ? number_format($mb, 1, ',', '.').' MB' : number_format($bytes / 1024, 0).' KB';
    }
}
