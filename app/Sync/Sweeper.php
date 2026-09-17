<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Süpürücü: Eloquent olayı üretmeyen yazmaları (DB::table, toplu update, pivot attach/detach,
 * saveQuietly) yakalar. Her satırın özetini sync_row_hashes'te tutar; özet değişmiş ve arada
 * günlüğe yazılmış bir değişiklik yoksa tam satırı 'sweep' kaynaklı değişiklik olarak yazar.
 * uuid'i boş satırlara uuid verir. İlk çalıştırma (baseline) günlüğe yazmaz.
 *
 * Hızlı kip: tablonun parmak izi (satır sayısı + son id + son updated_at) değişmediyse tablo atlanır.
 * Tam kip (zamanlayıcıda seyrek) tüm satırları karşılaştırır.
 */
class Sweeper
{
    /** Özete girmeyen sütunlar (yalnız zaman damgası/kimlik değişimi değişiklik sayılmaz) */
    private const HASH_IGNORE = ['uuid', 'updated_at'];

    public function __construct(
        private readonly SyncSchema $schema,
        private readonly RowCodec $codec,
        private readonly ChangeRecorder $recorder,
        private readonly SyncContext $context,
        private readonly BranchResolver $branches,
    ) {}

    /** @return array<string, SyncTable> bu düğümde süpürülecek tablolar */
    public function tables(): array
    {
        $local = ChangeRecorder::isLocalNode();

        return array_filter(SyncRegistry::synced(), function (SyncTable $t) use ($local) {
            if (! $this->schema->tableExists($t->table)) {
                return false;
            }
            if (! $t->isPivot() && ! $this->schema->hasUuid($t->table)) {
                return false;
            }

            return $local ? $t->isPushable() : $t->direction !== 'up';
        });
    }

    /**
     * @param list<string>|null $only
     * @return array{tables: int, scanned: int, inserted: int, updated: int, deleted: int, skipped: int, ms: int}
     */
    public function sweep(?array $only = null, bool $full = false): array
    {
        $t0 = hrtime(true);
        $stats = ['tables' => 0, 'scanned' => 0, 'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'ms' => 0];
        if (! $this->recorder->enabled()) {
            return $stats;
        }
        $states = DB::table('sync_table_state')->get()->keyBy('table_name')->map(fn ($r) => (array) $r)->all();

        foreach ($this->tables() as $table => $def) {
            if ($only !== null && ! in_array($table, $only, true)) {
                continue;
            }
            $state = $states[$table] ?? null;
            if (! $state || ! $state['baselined_at']) {
                continue;   // önce baseline (kurs:sync-prepare)
            }
            $fingerprint = $this->fingerprint($def);
            if (! $full && $fingerprint === $state['fingerprint']) {
                $stats['skipped']++;

                continue;
            }
            $s = $this->sweepTable($def, true);
            DB::table('sync_table_state')->where('table_name', $table)->update([
                'last_swept_at' => now(), 'rows' => $s['rows'], 'fingerprint' => $this->fingerprint($def),
            ]);
            $stats['tables']++;
            foreach (['scanned', 'inserted', 'updated', 'deleted'] as $k) {
                $stats[$k] += $s[$k];
            }
        }
        $stats['ms'] = (int) ((hrtime(true) - $t0) / 1e6);

        return $stats;
    }

    /**
     * Günlüğe yazmadan özetleri kaydeder (ilk kurulum / sonradan eklenen tablo).
     *
     * @param list<string>|null $only
     * @return array<string, int> tablo => satır
     */
    public function baseline(?array $only = null, bool $force = false): array
    {
        $out = [];
        foreach ($this->tables() as $table => $def) {
            if ($only !== null && ! in_array($table, $only, true)) {
                continue;
            }
            $exists = DB::table('sync_table_state')->where('table_name', $table)->whereNotNull('baselined_at')->exists();
            if ($exists && ! $force) {
                continue;
            }
            $s = $this->sweepTable($def, false);
            DB::table('sync_table_state')->updateOrInsert(['table_name' => $table], [
                'baselined_at' => now(), 'last_swept_at' => now(), 'rows' => $s['rows'], 'fingerprint' => $this->fingerprint($def),
            ]);
            $out[$table] = $s['rows'];
        }

        return $out;
    }

    /** Hızlı değişiklik parmak izi. */
    public function fingerprint(SyncTable $def): string
    {
        $q = DB::table($def->table);
        $cols = $this->schema->columns($def->table);
        $select = ['COUNT(*) AS c'];
        if (in_array('id', $cols, true)) {
            $select[] = 'MAX(id) AS m';
        }
        if (in_array('updated_at', $cols, true)) {
            $select[] = 'MAX(updated_at) AS u';
        }
        $row = (array) $q->selectRaw(implode(', ', $select))->first();

        return implode('|', [$row['c'] ?? 0, $row['m'] ?? '', $row['u'] ?? '']);
    }

    /**
     * @return array{rows: int, scanned: int, inserted: int, updated: int, deleted: int}
     */
    public function sweepTable(SyncTable $def, bool $record): array
    {
        $table = $def->table;
        $pivot = $def->isPivot();
        $out = ['rows' => 0, 'scanned' => 0, 'inserted' => 0, 'updated' => 0, 'deleted' => 0];
        $canRecord = $record && (ChangeRecorder::isLocalNode() ? $def->isPushable() : $def->direction !== 'up');

        $stored = [];
        foreach (DB::table('sync_row_hashes')->where('table_name', $table)->get(['row_key', 'row_uuid', 'hash', 'change_id']) as $h) {
            $stored[$h->row_key] = [$h->row_uuid, $h->hash, (int) $h->change_id];
        }
        $maxChange = (int) (DB::table('sync_changes')->max('id') ?? 0);
        $seen = [];
        $upserts = [];

        $process = function (array $row) use ($def, $table, $pivot, $canRecord, &$stored, &$seen, &$upserts, &$out, &$maxChange) {
            $out['rows']++;
            $out['scanned']++;
            $key = $pivot ? $this->pivotKey($def, $row) : (string) $row['id'];
            $seen[$key] = true;
            $hash = RowCodec::hash(array_diff_key($row, array_flip([...self::HASH_IGNORE, ...$def->exclude])));
            $prev = $stored[$key] ?? null;

            if ($prev !== null && $prev[1] === $hash) {
                return;
            }

            $uuid = $pivot ? $this->pivotUuid($def, $row) : ($row['uuid'] ?? null);
            if (! $pivot && ! $uuid) {
                $uuid = $this->context->nextUuid($table);
                DB::table($table)->where('id', $row['id'])->whereNull('uuid')->update(['uuid' => $uuid]);
                $row['uuid'] = $uuid;
                $this->codec->remember($table, (int) $row['id'], $uuid);
            }
            $changeId = $maxChange;

            if ($canRecord && $uuid && SyncFilters::allows($def, $row)) {
                if ($prev === null) {
                    // Eloquent ile eklenip zaten günlüğe yazılmış satır tekrar yazılmaz
                    $logged = DB::table('sync_changes')->where('table_name', $table)->where('row_uuid', $uuid)
                        ->orderByDesc('id')->first(['id', 'op']);
                    if ($logged && $logged->op !== 'delete') {
                        $changeId = max($changeId, (int) $logged->id);
                    } else {
                        $changeId = $this->recorder->write($table, $uuid, 'insert', $this->encodeFull($def, $row),
                            $this->branches->resolve($def, $row), ['source' => ChangeRecorder::isLocalNode() ? 'local' : 'sweep']);
                        $out['inserted']++;
                    }
                } elseif (! $pivot) {
                    // Özet farklı: günlüğe yazılmamış (olaysız) bir yazma olmuş
                    $changeId = $this->recorder->write($table, $uuid, 'update', $this->encodeFull($def, $row),
                        $this->branches->resolve($def, $row), ['source' => ChangeRecorder::isLocalNode() ? 'local' : 'sweep']);
                    $out['updated']++;
                }
            }
            $upserts[] = ['table_name' => $table, 'row_key' => $key, 'row_uuid' => $uuid, 'hash' => $hash, 'change_id' => $changeId];
            if (count($upserts) >= 150) {   // SQLite 3.26: en fazla 999 bağlı değişken
                $this->flushHashes($upserts);
            }
        };

        if ($pivot) {
            foreach (DB::table($table)->orderBy($def->key[0])->get() as $r) {
                $process((array) $r);
            }
        } else {
            DB::table($table)->chunkById(1000, function ($rows) use ($process) {
                foreach ($rows as $r) {
                    $process((array) $r);
                }
            });
        }
        $this->flushHashes($upserts);

        // Silinen satırlar
        $gone = array_diff_key($stored, $seen);
        foreach (array_chunk(array_keys($gone), 400, true) as $keys) {
            foreach ($keys as $key) {
                [$uuid] = $gone[$key];
                if ($canRecord && $uuid) {
                    $this->recorder->write($table, $uuid, 'delete', $pivot ? $this->pivotFieldsFromKey($def, (string) $key) : null,
                        null, ['source' => ChangeRecorder::isLocalNode() ? 'local' : 'sweep']);
                    $out['deleted']++;
                }
            }
            DB::table('sync_row_hashes')->where('table_name', $table)->whereIn('row_key', array_map('strval', $keys))->delete();
        }

        return $out;
    }

    /** Uygulayıcı yazdığı satırların özetini günceller (geri itilmesin). */
    public function refresh(SyncTable $def, array $where): void
    {
        if (! $this->recorder->enabled()) {
            return;
        }
        $rows = DB::table($def->table)->where($where)->get();
        $upserts = [];
        foreach ($rows as $r) {
            $row = (array) $r;
            $key = $def->isPivot() ? $this->pivotKey($def, $row) : (string) $row['id'];
            $upserts[] = [
                'table_name' => $def->table, 'row_key' => $key,
                'row_uuid' => $def->isPivot() ? $this->pivotUuid($def, $row) : ($row['uuid'] ?? null),
                'hash' => RowCodec::hash(array_diff_key($row, array_flip([...self::HASH_IGNORE, ...$def->exclude]))),
                'change_id' => 0,
            ];
        }
        $this->flushHashes($upserts);
    }

    public function forget(SyncTable $def, string $rowKey): void
    {
        DB::table('sync_row_hashes')->where('table_name', $def->table)->where('row_key', $rowKey)->delete();
    }

    private function flushHashes(array &$upserts): void
    {
        if ($upserts === []) {
            return;
        }
        foreach (array_chunk($upserts, 150) as $part) {
            DB::table('sync_row_hashes')->upsert($part, ['table_name', 'row_key'], ['row_uuid', 'hash', 'change_id']);
        }
        $upserts = [];
    }

    public function pivotKey(SyncTable $def, array $row): string
    {
        return implode('|', array_map(fn ($c) => (string) ($row[$c] ?? ''), $def->key));
    }

    /** Pivot kimliği: anahtar sütunları uuid'ye çevrilerek. */
    public function pivotUuid(SyncTable $def, array $row): ?string
    {
        $encoded = $this->codec->encode($def->table, array_intersect_key($row, array_flip($def->key)) + $row);
        $key = array_intersect_key($encoded, array_flip($def->key));
        if (count($key) !== count($def->key) || in_array(null, $key, true)) {
            return null;
        }

        return RowCodec::pivotUuid($def->table, $key);
    }

    /** Silinen pivot satırı için anahtar alanları (tel biçiminde). */
    private function pivotFieldsFromKey(SyncTable $def, string $key): ?array
    {
        $parts = explode('|', $key);
        if (count($parts) !== count($def->key)) {
            return null;
        }
        $row = array_combine($def->key, $parts);

        return array_intersect_key($this->codec->encode($def->table, $row, false), $row);
    }

    private function encodeFull(SyncTable $def, array $row): array
    {
        return $this->codec->encode($def->table, $row);
    }
}
