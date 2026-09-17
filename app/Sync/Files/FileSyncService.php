<?php

namespace App\Sync\Files;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Support\Audit;
use App\Sync\Models\SyncDevice;
use App\Sync\Server\PullService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sunucu: dosya eşitleme uçları (yalnız cihaz jetonu; şube + yetki kapsamı).
 *  manifest(since)  → değişen dosya kayıtları (sha256, boyut, disk, yol, sahip)
 *  download(sha)    → içerik (gönderilmeden önce özet doğrulanır)
 *  upload(...)      → cihazda oluşan dosya; sahibi sunucuda olmalı, var olan dosyanın üzerine YAZILMAZ
 */
class FileSyncService
{
    public function __construct(private readonly FileIndex $index, private readonly PullService $pull) {}

    public static function maxBytes(): int
    {
        return max(1, (int) config('sync.file_max_mb', 20)) * 1024 * 1024;
    }

    public function indexIfDue(): void
    {
        $every = (int) config('sync.files_index_seconds', 60);
        if ($every > 0 && ! Cache::add('sync:files:index:recent', 1, $every)) {
            return;
        }
        $lock = Cache::lock('sync:files:index', 300);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->index->refresh();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{files: list<array<string, mixed>>, next: ?string, more: bool}
     */
    public function manifest(SyncDevice $device, User $user, ?string $since, int $limit): array
    {
        $this->indexIfDue();
        $limit = max(1, min(1000, $limit));
        $can = $this->pull->access($user);
        [$sinceAt, $sinceId] = self::parseCursor($since);

        $q = DB::table('sync_files')->where(fn ($w) => $w->where('branch_id', $device->branch_id)->orWhereNull('branch_id'))
            ->whereNotNull('sha256')->orderBy('updated_at')->orderBy('id');
        if ($sinceAt !== null) {
            $q->where(fn ($w) => $w->where('updated_at', '>', $sinceAt)->orWhere(fn ($x) => $x->where('updated_at', $sinceAt)->where('id', '>', $sinceId)));
        }
        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $files = [];
        foreach ($rows as $r) {
            if (! FileSources::allowed(self::entry($r), $can)) {
                continue;
            }
            $files[] = [
                'disk' => $r->disk, 'path' => $r->path, 'sha256' => $r->sha256, 'size' => (int) $r->size, 'mime' => $r->mime,
                'owner_table' => $r->owner_table, 'owner_uuid' => $r->owner_uuid, 'status' => $r->status === 'gone' ? 'gone' : 'present',
            ];
        }
        $last = $rows->last();

        return ['files' => $files, 'next' => $last ? self::cursor((string) $last->updated_at, (int) $last->id) : $since, 'more' => $more];
    }

    public function download(SyncDevice $device, User $user, string $sha): BinaryFileResponse
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $sha)) {
            throw new BusinessRuleException('Geçersiz dosya özeti.', 'invalid_hash', [], 422);
        }
        $can = $this->pull->access($user);
        $rows = DB::table('sync_files')->where('sha256', $sha)->where('status', 'present')
            ->where(fn ($w) => $w->where('branch_id', $device->branch_id)->orWhereNull('branch_id'))->orderByDesc('id')->get();
        foreach ($rows as $r) {
            if (! FileSources::allowed(self::entry($r), $can)) {
                continue;
            }
            $full = Storage::disk($r->disk)->path($r->path);
            if (! is_file($full)) {
                continue;
            }
            if (filesize($full) > self::maxBytes()) {
                throw new BusinessRuleException('Dosya eşitleme boyut sınırını aşıyor.', 'file_too_large', ['max_mb' => (int) config('sync.file_max_mb', 20)], 413);
            }
            if (hash_file('sha256', $full) !== $sha) {
                // Dosya diskte değişmiş: dizin bir sonraki taramada düzelir
                Cache::forget('sync:files:index:recent');

                continue;
            }

            return response()->file($full, [
                'Content-Type' => 'application/octet-stream',
                'X-Content-SHA256' => $sha,
                'Cache-Control' => 'no-store',
            ]);
        }

        throw new BusinessRuleException('Dosya bulunamadı ya da bu cihazın erişimi yok.', 'file_not_found', [], 404);
    }

    /** @return array{status: string, sha256: string} */
    public function upload(SyncDevice $device, User $user, UploadedFile $file, string $disk, string $path, string $sha): array
    {
        if (! FileSources::safePath($disk, $path)) {
            throw new BusinessRuleException('Bu dosya yolu eşitlemede kabul edilmiyor.', 'invalid_path', [], 422);
        }
        if ($file->getSize() > self::maxBytes()) {
            throw new BusinessRuleException('Dosya eşitleme boyut sınırını aşıyor.', 'file_too_large', ['max_mb' => (int) config('sync.file_max_mb', 20)], 413);
        }
        $actual = hash_file('sha256', $file->getRealPath());
        if (! hash_equals($actual, strtolower($sha))) {
            throw new BusinessRuleException('Dosya içeriği gönderilen özetle uyuşmuyor (aktarım bozuk).', 'hash_mismatch', [], 422);
        }
        $owner = FileSources::ownerFor($disk, $path);
        if (! $owner) {
            throw new BusinessRuleException('Dosyanın bağlı olduğu kayıt sunucuda henüz yok; daha sonra tekrar denenecek.', 'owner_missing', [], 404);
        }
        if ($owner['branch_id'] !== null && (int) $owner['branch_id'] !== (int) $device->branch_id) {
            throw new BusinessRuleException('Dosyanın kaydı bu cihazın şubesine ait değil.', 'branch_mismatch', [], 403);
        }
        if (! FileSources::allowed($owner, $this->pull->access($user))) {
            throw new BusinessRuleException('Bu dosya için yetkiniz yok.', 'forbidden', [], 403);
        }

        $storage = Storage::disk($disk);
        $full = $storage->path($path);
        if (is_file($full)) {
            if (hash_file('sha256', $full) === $actual) {
                return ['status' => 'duplicate', 'sha256' => $actual];
            }
            throw new BusinessRuleException('Sunucuda aynı yolda farklı içerikli dosya var; üzerine yazılmadı.', 'file_conflict', [], 409);
        }
        $stored = $storage->putFileAs(dirname($path), $file, basename($path));
        if (! $stored) {
            throw new BusinessRuleException('Dosya kaydedilemedi.', 'upload_failed', [], 500);
        }
        $now = now()->format('Y-m-d H:i:s.v');
        DB::table('sync_files')->upsert([[
            'disk' => $disk, 'path' => $path, 'sha256' => $actual, 'size' => (int) filesize($full), 'mime' => FileIndex::mime($full),
            'file_mtime' => (int) filemtime($full), 'status' => 'present', 'branch_id' => $owner['branch_id'] ?? $device->branch_id,
            'owner_table' => $owner['owner_table'], 'owner_uuid' => $owner['owner_uuid'],
            'owner_column' => substr($owner['source'], strlen($owner['owner_table']) + 1), 'owner_kind' => $owner['documentable_type'],
            'created_at' => $now, 'updated_at' => $now,
        ]], ['disk', 'path'], ['sha256', 'size', 'mime', 'file_mtime', 'status', 'branch_id', 'owner_table', 'owner_uuid', 'owner_column', 'owner_kind', 'updated_at']);
        Audit::log('sync.file_uploaded', sprintf('"%s" cihazından dosya eşitlendi (%s, %d KB).', $device->name, basename($path), (int) ceil(filesize($full) / 1024)));

        return ['status' => 'stored', 'sha256' => $actual];
    }

    /** @return array{source: string, owner_table: string, documentable_type: ?string} */
    public static function entry(object $r): array
    {
        return ['source' => $r->owner_table.'.'.$r->owner_column, 'owner_table' => $r->owner_table, 'documentable_type' => $r->owner_kind ?? null];
    }

    public static function cursor(string $at, int $id): string
    {
        return $at.'|'.$id;
    }

    /** @return array{0: ?string, 1: int} */
    public static function parseCursor(?string $cursor): array
    {
        if (! $cursor || ! str_contains($cursor, '|')) {
            return [null, 0];
        }
        [$at, $id] = explode('|', $cursor, 2);

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $at) ? [$at, (int) $id] : [null, 0];
    }
}
