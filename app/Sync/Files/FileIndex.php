<?php

namespace App\Sync\Files;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * İçerik adresli dosya dizini (sync_files). Her iki düğümde de çalışır:
 *  - sunucu: başvurulan her dosyanın sha256'sı (değişen/yeni dosyalar yeniden özetlenir; başvurusu kalkan 'gone')
 *  - yerel : aynı tarama, yeni yerel dosyalar 'upload' kuyruğuna girer (LocalFileSync)
 */
class FileIndex
{
    /**
     * @param list<string>|null $only kaynak süzgeci
     * @return array{scanned: int, hashed: int, gone: int, new: list<string>}
     */
    public function refresh(?array $only = null, bool $local = false): array
    {
        $refs = FileSources::referenced($only);
        $existing = [];
        foreach (DB::table('sync_files')->get(['id', 'disk', 'path', 'sha256', 'size', 'file_mtime', 'status', 'owner_uuid', 'owner_kind', 'branch_id']) as $r) {
            $existing[$r->disk.'|'.$r->path] = $r;
        }
        $stats = ['scanned' => 0, 'hashed' => 0, 'gone' => 0, 'new' => []];
        $now = now()->format('Y-m-d H:i:s.v');

        foreach ($refs as $key => $ref) {
            if ($only !== null && ! in_array($ref['source'], $only, true)) {
                continue;
            }
            $stats['scanned']++;
            $row = $existing[$key] ?? null;
            unset($existing[$key]);
            $disk = Storage::disk($ref['disk']);
            $full = $disk->path($ref['path']);
            $exists = is_file($full);
            $mtime = $exists ? (int) filemtime($full) : null;
            $size = $exists ? (int) filesize($full) : 0;
            $meta = [
                'branch_id' => $ref['branch_id'], 'owner_table' => $ref['owner_table'], 'owner_uuid' => $ref['owner_uuid'],
                'owner_column' => substr($ref['source'], strlen($ref['owner_table']) + 1), 'owner_kind' => $ref['documentable_type'],
            ];

            if ($row && $exists && $row->sha256 && (int) $row->file_mtime === $mtime && (int) $row->size === $size) {
                // değişmemiş dosya: yalnız sahip bilgisi tazelenir
                if ($row->status === 'gone' || $row->owner_uuid !== $meta['owner_uuid'] || $row->owner_kind !== $meta['owner_kind']) {
                    DB::table('sync_files')->where('id', $row->id)->update($meta + ['status' => 'present', 'updated_at' => $now]);
                }

                continue;
            }
            if (! $exists) {
                if ($local) {
                    continue;   // yerelde eksik dosya: manifest indirme kuyruğuna koyar
                }
                if ($row && $row->status !== 'gone') {
                    DB::table('sync_files')->where('id', $row->id)->update(['status' => 'gone', 'updated_at' => $now]);
                    $stats['gone']++;
                }

                continue;
            }
            $sha = hash_file('sha256', $full);
            $stats['hashed']++;
            $values = $meta + [
                'sha256' => $sha, 'size' => $size, 'mime' => self::mime($full), 'file_mtime' => $mtime, 'updated_at' => $now,
            ];
            if ($row) {
                $changed = $row->sha256 !== $sha;
                if ($local && $changed) {
                    $values += ['status' => 'upload', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null];
                } elseif (! $local) {
                    $values['status'] = 'present';
                }
                DB::table('sync_files')->where('id', $row->id)->update($values);
            } else {
                DB::table('sync_files')->insert($values + [
                    'disk' => $ref['disk'], 'path' => $ref['path'], 'created_at' => $now,
                    'status' => $local ? (FileSources::SOURCES[$ref['source']]['up'] ? 'upload' : 'present') : 'present',
                ]);
                $stats['new'][] = $key;
            }
        }

        // Başvurusu kalmayan dosyalar (satır silindi / yol değişti)
        foreach ($existing as $row) {
            if ($only !== null || in_array($row->status, ['gone'], true)) {
                continue;
            }
            if ($local && in_array($row->status, ['download', 'failed'], true)) {
                continue;   // sunucudan inmesi beklenen dosyanın satırı henüz gelmemiş olabilir
            }
            DB::table('sync_files')->where('id', $row->id)->update(['status' => 'gone', 'updated_at' => $now]);
            $stats['gone']++;
        }

        return $stats;
    }

    public static function mime(string $full): string
    {
        $m = @mime_content_type($full);

        return is_string($m) && $m !== '' ? mb_substr($m, 0, 120) : 'application/octet-stream';
    }
}
