<?php

namespace App\Sync\Server;

use App\Models\User;
use App\Sync\Models\SyncDevice;
use App\Sync\RowCodec;
use App\Sync\SyncFilters;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use App\Sync\SyncTable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * İlk kurulum anlık görüntüsü: bağımlılık sırasına göre tablolar, her tablo parça parça (id sırası).
 * Görüntü başlarken alınan imleçten sonra cihaz normal çekmeye geçer (arada değişen satırlar tekrar
 * gelir; uygulama idempotent).
 */
class SnapshotService
{
    public function __construct(
        private readonly SyncSchema $schema,
        private readonly RowCodec $codec,
        private readonly PullService $pull,
    ) {}

    /** @return array{cursor: int, server_time: string, tables: list<array{table: string, kind: string, rows: int, pivot: bool}>} */
    public function manifest(SyncDevice $device, User $user): array
    {
        $this->pull->sweepIfDue();
        $cursor = (int) (DB::table('sync_changes')->max('id') ?? 0);
        $access = $this->pull->access($user);
        $tables = [];
        foreach ($this->orderedTables() as $def) {
            if ($def->permission && ! $access($def->permission)) {
                continue;
            }
            $tables[] = [
                'table' => $def->table,
                'kind' => $def->kind,
                'pivot' => $def->isPivot(),
                'rows' => (int) $this->query($device, $def)->count(),
            ];
        }

        return ['cursor' => $cursor, 'server_time' => now()->toIso8601String(), 'tables' => $tables];
    }

    /**
     * @return array{table: string, rows: list<array{row: string, fields: array<string, mixed>}>, next: ?int}
     */
    public function page(SyncDevice $device, User $user, string $table, int $after, int $limit): array
    {
        $def = SyncRegistry::get($table);
        if (! $def || ! $def->isPullable() || ! $this->schema->tableExists($table)) {
            throw new SyncReject('Tablo anlık görüntüde yok: '.$table, 'unknown_table');
        }
        $access = $this->pull->access($user);
        if ($def->permission && ! $access($def->permission)) {
            throw new SyncReject('Bu tablo için yetki yok.', 'forbidden');
        }
        $limit = max(1, min((int) config('sync.snapshot_limit', 1000), $limit));
        $q = $this->query($device, $def);
        if ($def->isPivot()) {
            $q->orderBy($def->key[0]);
            foreach (array_slice($def->key, 1) as $k) {
                $q->orderBy($k);
            }
            $items = $q->offset($after)->limit($limit + 1)->get();
            $next = $items->count() > $limit ? $after + $limit : null;
        } else {
            $items = $q->where($def->table.'.id', '>', $after)->orderBy($def->table.'.id')->limit($limit + 1)->get();
            $next = $items->count() > $limit ? (int) $items->take($limit)->last()->id : null;
        }

        $rows = [];
        foreach ($items->take($limit) as $item) {
            $raw = (array) $item;
            $fields = $this->codec->encode($table, $raw);
            foreach ($def->sensitive as $col => $perm) {
                if (! $access($perm)) {
                    unset($fields[$col]);
                }
            }
            $uuid = $def->isPivot()
                ? RowCodec::pivotUuid($table, array_intersect_key($fields, array_flip($def->key)))
                : ($raw['uuid'] ?? $this->codec->uuidFor($table, $raw['id']));
            $rows[] = ['row' => $uuid, 'fields' => $fields];
        }

        return ['table' => $table, 'rows' => $rows, 'next' => $next];
    }

    /**
     * Belirli satırların güncel hali (cihaz onarımı: eksik satır, reddedilen komutun geri alınması).
     *
     * @param list<string> $uuids
     * @return list<array{row: string, fields: array<string, mixed>|null}>
     */
    public function rows(SyncDevice $device, User $user, string $table, array $uuids): array
    {
        $def = SyncRegistry::get($table);
        if (! $def || ! $def->hasUuid() || ! $def->isPullable()) {
            throw new SyncReject('Tablo okunamaz: '.$table, 'unknown_table');
        }
        $access = $this->pull->access($user);
        if ($def->permission && ! $access($def->permission)) {
            throw new SyncReject('Bu tablo için yetki yok.', 'forbidden');
        }
        $found = $this->query($device, $def)->whereIn($table.'.uuid', array_slice($uuids, 0, 500))->get()->keyBy('uuid');
        $out = [];
        foreach ($uuids as $uuid) {
            $raw = isset($found[$uuid]) ? (array) $found[$uuid] : null;
            $fields = $raw ? $this->codec->encode($table, $raw) : null;
            if ($fields) {
                foreach ($def->sensitive as $col => $perm) {
                    if (! $access($perm)) {
                        unset($fields[$col]);
                    }
                }
            }
            $out[] = ['row' => $uuid, 'fields' => $fields];
        }

        return $out;
    }

    /** Şube kapsamlı sorgu. */
    public function query(SyncDevice $device, SyncTable $def): Builder
    {
        $q = DB::table($def->table)->select($def->table.'.*');
        SyncFilters::applyToQuery($def, $q);
        $this->scope($q, $def, (int) $device->branch_id, $def->table.'.');

        return $q;
    }

    private function scope(Builder $q, SyncTable $def, int $branchId, string $prefix, int $depth = 0): void
    {
        $columns = $this->schema->columns($def->table);
        if ($def->branch === 'global' || $depth > 4) {
            return;
        }
        if ($def->branch === 'self') {
            $q->where($prefix.'id', $branchId);

            return;
        }
        if (in_array('branch_id', $columns, true)) {
            $q->where(fn ($w) => $w->where($prefix.'branch_id', $branchId)->orWhereNull($prefix.'branch_id'));

            return;
        }
        if (str_starts_with($def->branch, 'via:')) {
            $column = substr($def->branch, 4);
            $target = $this->schema->refs($def->table)[$column] ?? null;
            $parent = $target ? SyncRegistry::get($target) : null;
            if ($parent && ! str_starts_with($target, 'morph:')) {
                $sub = DB::table($target)->select($target.'.id');
                $this->scope($sub, $parent, $branchId, $target.'.', $depth + 1);
                $q->whereIn($prefix.$column, $sub);
            }
        }
    }

    /**
     * Bağımlılık (referans) sırasına göre indirilebilir tablolar.
     *
     * @return list<SyncTable>
     */
    public function orderedTables(): array
    {
        $defs = array_filter(SyncRegistry::synced(), fn (SyncTable $t) => $t->isPullable()
            && $this->schema->tableExists($t->table) && ($t->isPivot() || $this->schema->hasUuid($t->table)));
        $deps = [];
        foreach ($defs as $table => $def) {
            $deps[$table] = [];
            foreach ($this->schema->refs($table) as $target) {
                if (! str_starts_with($target, 'morph:') && $target !== $table && isset($defs[$target])) {
                    $deps[$table][] = $target;
                }
            }
        }
        $ordered = [];
        $visiting = [];
        $visit = function (string $t) use (&$visit, &$ordered, &$visiting, $deps) {
            if (isset($ordered[$t]) || isset($visiting[$t])) {
                return;   // döngü: tanım sırası kazanır, çözülmeyen referans sonradan düzeltilir
            }
            $visiting[$t] = true;
            foreach ($deps[$t] as $d) {
                $visit($d);
            }
            unset($visiting[$t]);
            $ordered[$t] = true;
        };
        foreach (array_keys($defs) as $t) {
            $visit($t);
        }

        return array_values(array_map(fn ($t) => $defs[$t], array_keys($ordered)));
    }
}
