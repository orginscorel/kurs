<?php

namespace App\Sync\Local;

use App\Sync\Files\FileIndex;
use App\Sync\Files\FileSources;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Yerel düğüm dosya eşitlemesi (kurs:sync turunun son adımı; satırlar gönderilip çekildikten sonra).
 *  1. yerel tarama: satırların başvurduğu yeni yerel dosyalar 'upload' kuyruğuna
 *  2. sunucu manifesti (imleçli): eksik/farklı dosyalar 'download' kuyruğuna
 *  3. yükleme ve indirme: tur başına sınırlı adet; hata → üstel geri çekilme (15 sn … 6 sa), kuyrukta kalır
 * Dosyalar sunucudaki AYNI göreli yola yazılır (storage/app/public | storage/app/private).
 */
class LocalFileSync
{
    public const MAX_ATTEMPTS_BEFORE_FAILED = 12;

    public function __construct(
        private readonly SyncClient $client,
        private readonly LocalState $state,
        private readonly FileIndex $index,
    ) {}

    /** @return array{scan: array, manifest: int, uploaded: int, downloaded: int, failed: int, pending: int} */
    public function run(): array
    {
        $out = ['scan' => $this->index->refresh(null, true), 'manifest' => 0, 'uploaded' => 0, 'downloaded' => 0, 'failed' => 0, 'pending' => 0];
        $out['manifest'] = $this->pullManifest();
        $budget = max(1, (int) config('sync.files_per_cycle', 40));

        foreach ($this->due('upload', $budget) as $row) {
            $this->uploadOne($row) ? $out['uploaded']++ : $out['failed']++;
        }
        foreach ($this->due('download', $budget) as $row) {
            $this->downloadOne($row) ? $out['downloaded']++ : $out['failed']++;
        }
        $out['pending'] = (int) DB::table('sync_files')->whereIn('status', ['upload', 'download', 'failed'])->count();
        $this->state->put('files_pending', $out['pending']);

        return $out;
    }

    private function pullManifest(): int
    {
        $n = 0;
        for ($guard = 0; $guard < 200; $guard++) {
            $cursor = $this->state->get('files_cursor');
            $res = $this->client->fileManifest($cursor, 500);
            DB::transaction(function () use ($res, &$n) {
                foreach ($res['files'] ?? [] as $f) {
                    $n++;
                    $this->applyManifestEntry($f);
                }
            });
            $this->state->put('files_cursor', $res['next'] ?? $cursor);
            if (empty($res['more']) || ($res['next'] ?? null) === $cursor) {
                break;
            }
        }

        return $n;
    }

    /** @param array{disk: string, path: string, sha256: string, size: int, mime: ?string, owner_table: string, owner_uuid: ?string, status: string} $f */
    public function applyManifestEntry(array $f): void
    {
        if (! in_array($f['disk'] ?? '', FileSources::DISKS, true) || ! is_string($f['path'] ?? null) || str_contains($f['path'], '..')
            || ! preg_match('/^[0-9a-f]{64}$/', (string) ($f['sha256'] ?? ''))) {
            return;
        }
        $now = now()->format('Y-m-d H:i:s.v');
        $row = DB::table('sync_files')->where('disk', $f['disk'])->where('path', $f['path'])->first();
        $full = Storage::disk($f['disk'])->path($f['path']);
        $localSha = is_file($full) ? ($row && $row->sha256 && (int) $row->file_mtime === (int) filemtime($full) ? $row->sha256 : hash_file('sha256', $full)) : null;

        if ($f['status'] === 'gone') {
            if ($row && $row->status !== 'upload') {
                DB::table('sync_files')->where('id', $row->id)->update(['status' => 'gone', 'updated_at' => $now]);
            }

            return;
        }
        $values = [
            'sha256' => $f['sha256'], 'size' => (int) $f['size'], 'mime' => $f['mime'] ?? null,
            'owner_table' => (string) $f['owner_table'], 'owner_uuid' => $f['owner_uuid'] ?? null, 'updated_at' => $now,
        ];
        if ($localSha === $f['sha256']) {
            $values += ['status' => 'present', 'file_mtime' => (int) filemtime($full), 'attempts' => 0, 'last_error' => null, 'next_attempt_at' => null];
        } else {
            // Eksik ya da farklı: sunucudaki içerik indirilir (yerelde yüklenmeyi bekleyen farklı dosya varsa dokunma)
            if ($row && $row->status === 'upload') {
                return;
            }
            if ($row && in_array($row->status, ['download', 'failed'], true) && $row->sha256 === $f['sha256']) {
                return;   // zaten kuyrukta: geri çekilme sayacı sıfırlanmasın
            }
            $values += ['status' => 'download', 'attempts' => 0, 'last_error' => null, 'next_attempt_at' => null, 'file_mtime' => null];
        }
        if ($row) {
            DB::table('sync_files')->where('id', $row->id)->update($values);
        } else {
            DB::table('sync_files')->insert($values + ['disk' => $f['disk'], 'path' => $f['path'], 'owner_column' => '', 'created_at' => $now]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function due(string $status, int $limit)
    {
        return DB::table('sync_files')
            ->where(fn ($w) => $w->where('status', $status)->orWhere(fn ($x) => $x->where('status', 'failed')->where('last_error', 'like', $status.':%')))
            ->where(fn ($w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()->format('Y-m-d H:i:s')))
            ->orderBy('attempts')->orderBy('id')->limit($limit)->get();
    }

    private function uploadOne(object $row): bool
    {
        $full = Storage::disk($row->disk)->path($row->path);
        if (! is_file($full)) {
            DB::table('sync_files')->where('id', $row->id)->update(['status' => 'gone', 'updated_at' => now()->format('Y-m-d H:i:s.v')]);

            return true;
        }
        $sha = hash_file('sha256', $full);
        try {
            $this->client->uploadFile($row->disk, $row->path, $full, $sha);
            DB::table('sync_files')->where('id', $row->id)->update([
                'status' => 'present', 'sha256' => $sha, 'size' => (int) filesize($full), 'file_mtime' => (int) filemtime($full),
                'attempts' => 0, 'last_error' => null, 'next_attempt_at' => null, 'updated_at' => now()->format('Y-m-d H:i:s.v'),
            ]);

            return true;
        } catch (SyncHttpException $e) {
            if ($e->isOffline() || $e->isRevoked()) {
                throw $e;
            }
            // 409 (sunucuda farklı içerik) ve 413/422 kalıcı; 404 (kayıt henüz yok) ve diğerleri yeniden denenir
            $permanent = in_array($e->status, [409, 413, 422], true);
            $this->retryLater($row, 'upload', $e->getMessage(), $permanent);

            return false;
        }
    }

    private function downloadOne(object $row): bool
    {
        $disk = Storage::disk($row->disk);
        $full = $disk->path($row->path);
        $tmp = $full.'.part-'.bin2hex(random_bytes(4));
        try {
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0755, true);
            }
            $this->client->downloadFile((string) $row->sha256, $tmp);
            $got = hash_file('sha256', $tmp);
            if ($got !== $row->sha256) {
                @unlink($tmp);
                $this->retryLater($row, 'download', 'İndirilen dosyanın özeti uyuşmadı (aktarım bozuk).', false);

                return false;
            }
            rename($tmp, $full);
            DB::table('sync_files')->where('id', $row->id)->update([
                'status' => 'present', 'size' => (int) filesize($full), 'file_mtime' => (int) filemtime($full),
                'attempts' => 0, 'last_error' => null, 'next_attempt_at' => null, 'updated_at' => now()->format('Y-m-d H:i:s.v'),
            ]);

            return true;
        } catch (SyncHttpException $e) {
            @unlink($tmp);
            if ($e->isOffline() || $e->isRevoked()) {
                throw $e;
            }
            $this->retryLater($row, 'download', $e->getMessage(), in_array($e->status, [403, 413], true));

            return false;
        } catch (\Throwable $e) {
            @unlink($tmp);
            Log::warning('Dosya indirilemedi', ['path' => $row->path, 'e' => $e->getMessage()]);
            $this->retryLater($row, 'download', 'Yerel diske yazılamadı.', false);

            return false;
        }
    }

    private function retryLater(object $row, string $direction, string $message, bool $permanent): void
    {
        $attempts = (int) $row->attempts + 1;
        $failed = $permanent || $attempts >= self::MAX_ATTEMPTS_BEFORE_FAILED;
        // 15 sn, 30 sn, 1 dk … en çok 6 saat; kalıcı hatada 6 saatte bir yine denenir
        $delay = $permanent ? 21600 : min(21600, 15 * (2 ** min(12, $attempts - 1)));
        DB::table('sync_files')->where('id', $row->id)->update([
            'status' => $failed ? 'failed' : $direction,
            'attempts' => $attempts,
            'last_error' => mb_substr($direction.': '.$message, 0, 300),
            'next_attempt_at' => now()->addSeconds($delay)->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
    }
}
