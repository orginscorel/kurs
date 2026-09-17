<?php

namespace App\Sync\Files;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eşitlenen satırların başvurduğu dosyalar (docs/SYNC.md › Dosyalar). Her kaynak: tablo + yol sütunu + disk.
 * Dosya yolu satır alanı olarak normal eşitlenir; içerik bu dizinle (sha256) taşınır. Yol iki tarafta AYNIDIR
 * (öğrenci/ödev/disiplin dosya adları rastgele ek taşıdığı için çakışmaz).
 *
 *  up   : yerelde oluşan dosya sunucuya yüklenebilir (sahibi itilebilen tablo)
 *  perm : sahibini görme yetkisi (cihaz kullanıcısında yoksa dosya inmez)
 */
final class FileSources
{
    /** @var array<string, array{table: string, column: string, disk: ?string, up: bool, perm: ?string}> */
    public const SOURCES = [
        'students.photo_path' => ['table' => 'students', 'column' => 'photo_path', 'disk' => 'public', 'up' => true, 'perm' => 'students.view'],
        'teachers.avatar_path' => ['table' => 'teachers', 'column' => 'avatar_path', 'disk' => 'public', 'up' => true, 'perm' => null],
        'users.avatar_path' => ['table' => 'users', 'column' => 'avatar_path', 'disk' => 'public', 'up' => false, 'perm' => null],
        'branches.logo_path' => ['table' => 'branches', 'column' => 'logo_path', 'disk' => 'public', 'up' => false, 'perm' => null],
        'settings.institution.logo_path' => ['table' => 'settings', 'column' => 'institution.logo_path', 'disk' => 'public', 'up' => false, 'perm' => null],
        // Belgeler: öğretmen belgeleri, ödev dosyaları ve öğrenci teslimleri (portal), disiplin ekleri
        'documents.path' => ['table' => 'documents', 'column' => 'path', 'disk' => null, 'up' => true, 'perm' => 'documents.view'],
    ];

    /** Belgenin sahibine göre ek yetki */
    public const DOCUMENT_PERMS = [
        'discipline_incident' => 'discipline.view',
        'homework' => 'homework.view',
        'homework_submission' => 'homework.view',
        'teacher' => 'teachers.view',
        'student' => 'students.view',
    ];

    public const DISKS = ['public', 'local'];

    /** Yüklemede kabul edilen yol kökleri ve uzantılar */
    public const PATH_PATTERN = '#^(students|teachers|homework|discipline|documents|institution|branches|avatars)/[A-Za-z0-9._\-/]{1,200}$#';

    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'txt', 'zip', 'csv', 'odt', 'ods', 'heic'];

    public static function safePath(string $disk, string $path): bool
    {
        return in_array($disk, self::DISKS, true)
            && preg_match(self::PATH_PATTERN, $path) === 1
            && ! str_contains($path, '..') && ! str_contains($path, '//')
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Başvurulan dosyalar: (disk, yol) → sahip bilgisi.
     *
     * @param list<string>|null $only yalnız bu kaynaklar
     * @return array<string, array{disk: string, path: string, source: string, owner_table: string, owner_uuid: ?string, branch_id: ?int, documentable_type: ?string}>
     */
    public static function referenced(?array $only = null): array
    {
        $out = [];
        $add = function (string $source, string $disk, ?string $path, ?string $uuid, ?int $branch, ?string $docType = null) use (&$out) {
            $path = is_string($path) ? trim($path) : '';
            if ($path === '' || str_starts_with($path, 'http')) {
                return;
            }
            $out[$disk.'|'.$path] ??= [
                'disk' => $disk, 'path' => $path, 'source' => $source, 'owner_table' => self::SOURCES[$source]['table'],
                'owner_uuid' => $uuid, 'branch_id' => $branch, 'documentable_type' => $docType,
            ];
        };
        foreach (self::SOURCES as $key => $s) {
            if (($only !== null && ! in_array($key, $only, true)) || ! Schema::hasTable($s['table'])) {
                continue;
            }
            if ($s['table'] === 'settings') {
                foreach (DB::table('settings')->where('group', 'institution')->where('key', 'logo_path')->get(['uuid', 'branch_id', 'value']) as $r) {
                    $v = json_decode((string) $r->value, true);
                    $add($key, 'public', is_string($v) ? $v : null, $r->uuid ?? null, $r->branch_id !== null ? (int) $r->branch_id : null);
                }

                continue;
            }
            if (! Schema::hasColumn($s['table'], $s['column'])) {
                continue;
            }
            $q = DB::table($s['table'])->whereNotNull($s['column'])->where($s['column'], '!=', '');
            if (Schema::hasColumn($s['table'], 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            if ($s['table'] === 'users') {
                $q->whereIn('user_type', \App\Sync\SyncFilters::STAFF_TYPES);
            }
            $cols = ['uuid', $s['column']];
            if ($s['table'] === 'branches') {
                $cols[] = 'id';
            } elseif (Schema::hasColumn($s['table'], 'branch_id')) {
                $cols[] = 'branch_id';
            }
            if ($s['table'] === 'documents') {
                $cols[] = 'disk';
                $cols[] = 'documentable_type';
            }
            foreach ($q->get($cols) as $r) {
                $branch = $s['table'] === 'branches' ? (int) $r->id : (isset($r->branch_id) ? (int) $r->branch_id : null);
                $add($key, $s['disk'] ?? (string) ($r->disk ?: 'local'), $r->{$s['column']}, $r->uuid, $branch, $r->documentable_type ?? null);
            }
        }

        return $out;
    }

    /** Sahibin şubesi (teachers/users tablolarında şube yoksa null = tüm şubeler). */
    public static function ownerFor(string $disk, string $path): ?array
    {
        foreach (self::SOURCES as $key => $s) {
            if (! $s['up'] || ($s['disk'] !== null && $s['disk'] !== $disk)) {
                continue;
            }
            $q = DB::table($s['table'])->where($s['column'], $path);
            if ($s['table'] === 'documents') {
                $q->where('disk', $disk);
            }
            $row = $q->first();
            if ($row) {
                return ['source' => $key, 'owner_table' => $s['table'], 'owner_uuid' => $row->uuid ?? null,
                    'branch_id' => isset($row->branch_id) ? (int) $row->branch_id : null, 'documentable_type' => $row->documentable_type ?? null];
            }
        }

        return null;
    }

    /** @param callable(string): bool $can */
    public static function allowed(array $entry, callable $can): bool
    {
        $source = self::SOURCES[$entry['source'] ?? ''] ?? null;
        $perm = $source['perm'] ?? (collect(self::SOURCES)->firstWhere('table', $entry['owner_table'] ?? '')['perm'] ?? null);
        if ($perm && ! $can($perm)) {
            return false;
        }
        $extra = self::DOCUMENT_PERMS[$entry['documentable_type'] ?? ''] ?? null;

        return ! $extra || $can($extra);
    }
}
