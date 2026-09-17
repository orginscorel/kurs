<?php

namespace App\Sync\Local;

use App\Sync\RowCodec;
use App\Sync\Sweeper;
use App\Sync\SyncContext;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use App\Sync\SyncTable;
use Illuminate\Support\Facades\DB;

/**
 * Yerel düğüm: sunucudan gelen değişiklikleri yerel SQLite'a uygular (olay üretmeden, DB::table).
 *  - Referanslar uuid → yerel id çevrilir; henüz gelmemiş bağlı kayıt varsa değişiklik ertelenir.
 *  - Karşılıklı (reference/keyed) tablolarda yerelde henüz gönderilmemiş alan varsa o alan ezilmez
 *    (yerel değişiklik bir sonraki gönderimde sunucuda son-yazan-kazanır ile çözülür).
 *  - Sunucu-otoriteli tablolarda sunucu değeri koşulsuz yazılır.
 *  - Uygulanan satırın özeti güncellenir (süpürücü geri göndermesin).
 */
class LocalApplier
{
    /** @var array<string, true> bu turda değişen tablolar (yeniden hesaplama için) */
    public array $touched = [];

    /** @var array<string, array<string, true>> tam satırı sonradan getirilecekler */
    public array $needRows = [];

    public bool $snapshotMode = false;

    /** @var list<array{table: string, id: int, column: string, ref: string, uuid: string}> */
    public array $selfRefs = [];

    public function __construct(
        private readonly RowCodec $codec,
        private readonly SyncSchema $schema,
        private readonly Sweeper $sweeper,
        private readonly SyncContext $context,
    ) {}

    /**
     * @param array{table: string, row: string, op: string, fields: ?array} $change
     * @return 'applied'|'deferred'|'skipped'
     */
    public function apply(array $change): string
    {
        $def = SyncRegistry::get((string) $change['table']);
        if (! $def || ! $def->isSynced() || ! $this->schema->tableExists($def->table)) {
            return 'skipped';
        }
        if (! $def->isPivot() && ! $this->schema->hasUuid($def->table)) {
            return 'skipped';
        }
        $fields = is_array($change['fields'] ?? null) ? $change['fields'] : [];
        $uuid = (string) $change['row'];

        return $this->context->applying(function () use ($def, $change, $fields, $uuid) {
            $this->touched[$def->table] = true;

            return match ($change['op']) {
                'merge' => $this->merge($def, $uuid, (string) ($fields['alias'] ?? '')),
                'delete' => $this->delete($def, $uuid, $fields),
                default => $def->isPivot()
                    ? $this->upsertPivot($def, $fields)
                    : $this->upsert($def, $uuid, $fields, $change['op']),
            };
        });
    }

    private function upsert(SyncTable $def, string $uuid, array $fields, string $op): string
    {
        $table = $def->table;
        [$decoded, $missing] = $this->codec->decode($table, $fields);

        $existing = DB::table($table)->where('uuid', $uuid)->first();

        // Eksik bağlı kayıt: aynı tabloya öz-referans ise boş bırakıp sonra düzelt, değilse ertele
        foreach ($missing as $m) {
            if ($m['table'] === $table && $this->snapshotMode) {
                $decoded[$m['column']] = null;
                $this->selfRefs[] = ['table' => $table, 'uuid' => $uuid, 'column' => $m['column'], 'ref' => $m['uuid']];

                continue;
            }

            return 'deferred';
        }

        if (! $existing && $def->key !== []) {
            $existing = $this->findByKey($def, $decoded);
            if ($existing) {
                DB::table($table)->where('id', $existing->id)->update(['uuid' => $uuid]);
            }
        }

        if ($existing) {
            $apply = $decoded;
            if (! $def->isServerAuthoritative() && ! $this->snapshotMode) {
                foreach ($this->pendingFields($table, (string) ($existing->uuid ?? $uuid)) as $f) {
                    unset($apply[$f]);
                }
            }
            unset($apply['id']);
            if ($apply !== []) {
                DB::table($table)->where('id', $existing->id)->update($apply);
            }
            $id = (int) $existing->id;
        } else {
            if ($op === 'update') {
                // Yerelde olmayan satıra kısmi güncelleme: tam satır sunucudan istenir
                $this->needRows[$table][$uuid] = true;

                return 'deferred';
            }
            $decoded['uuid'] = $uuid;
            $id = (int) DB::table($table)->insertGetId($decoded);
        }
        $this->codec->remember($table, $id, $uuid);
        if (! $this->snapshotMode) {
            $this->sweeper->refresh($def, ['id' => $id]);
        }

        return 'applied';
    }

    private function upsertPivot(SyncTable $def, array $fields): string
    {
        [$decoded, $missing] = $this->codec->decode($def->table, $fields);
        if ($missing !== []) {
            return 'deferred';
        }
        $key = array_intersect_key($decoded, array_flip($def->key));
        if (count($key) !== count($def->key)) {
            return 'skipped';
        }
        DB::table($def->table)->insertOrIgnore($decoded);
        if (! $this->snapshotMode) {
            $this->sweeper->refresh($def, $key);
        }

        return 'applied';
    }

    private function delete(SyncTable $def, string $uuid, array $fields): string
    {
        if ($def->isPivot()) {
            [$decoded, $missing] = $this->codec->decode($def->table, $fields);
            $key = array_intersect_key($decoded, array_flip($def->key));
            if ($missing !== [] || count($key) !== count($def->key)) {
                return 'skipped';   // bağlı kayıt yoksa satır da yoktur
            }
            DB::table($def->table)->where($key)->delete();
            $this->sweeper->forget($def, implode('|', array_map(fn ($k) => (string) $key[$k], $def->key)));

            return 'applied';
        }
        $row = DB::table($def->table)->where('uuid', $uuid)->first();
        if (! $row) {
            return 'applied';
        }
        DB::table($def->table)->where('id', $row->id)->delete();
        $this->sweeper->forget($def, (string) $row->id);

        return 'applied';
    }

    /** Sunucu, cihazın uuid'ini (alias) kendi kaydına bağladı. */
    private function merge(SyncTable $def, string $canonical, string $alias): string
    {
        if ($alias === '' || $def->isPivot()) {
            return 'skipped';
        }
        $a = DB::table($def->table)->where('uuid', $alias)->first();
        if (! $a) {
            return 'applied';
        }
        $b = DB::table($def->table)->where('uuid', $canonical)->first();
        if (! $b) {
            DB::table($def->table)->where('id', $a->id)->update(['uuid' => $canonical]);
            $this->codec->remember($def->table, (int) $a->id, $canonical);
            $this->sweeper->refresh($def, ['id' => $a->id]);

            return 'applied';
        }
        // İkisi de var: yerel kopyaya bağlı kayıtları asıl kayda taşı, kopyayı sil
        foreach (SyncRegistry::synced() as $other) {
            if (! $this->schema->tableExists($other->table)) {
                continue;
            }
            foreach ($this->schema->refs($other->table) as $column => $target) {
                if ($target === $def->table) {
                    DB::table($other->table)->where($column, $a->id)->update([$column => $b->id]);
                }
            }
        }
        DB::table($def->table)->where('id', $a->id)->delete();
        $this->sweeper->forget($def, (string) $a->id);

        return 'applied';
    }

    /** Anlık görüntü sonrası öz-referansları doldur. */
    public function fixSelfRefs(): int
    {
        $n = 0;
        foreach ($this->selfRefs as $r) {
            $id = $this->codec->idFor($r['table'], $r['ref']);
            if ($id !== null) {
                DB::table($r['table'])->where('uuid', $r['uuid'])->update([$r['column'] => $id]);
                $n++;
            }
        }
        $this->selfRefs = [];

        return $n;
    }

    private function findByKey(SyncTable $def, array $decoded): ?object
    {
        $where = [];
        foreach ($def->key as $k) {
            if (! array_key_exists($k, $decoded) || $decoded[$k] === null || $decoded[$k] === '') {
                return null;
            }
            $where[$k] = $decoded[$k];
        }

        return DB::table($def->table)->where($where)->orderBy('id')->first();
    }

    /** @return list<string> yerelde henüz gönderilmemiş alanlar */
    private function pendingFields(string $table, string $uuid): array
    {
        $out = [];
        $pushed = (int) DB::table('sync_state')->where('key', 'pushed_up_to')->value('value');
        foreach (DB::table('sync_changes')->where('table_name', $table)->where('row_uuid', $uuid)
            ->where('id', '>', $pushed)->whereNull('status')->whereIn('op', ['insert', 'update'])->pluck('fields') as $json) {
            foreach (array_keys((array) json_decode((string) $json, true)) as $k) {
                $out[$k] = true;
            }
        }

        return array_keys($out);
    }
}
