<?php

namespace App\Sync;

use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Değişikliğin hangi şubeye ait olduğu (cihaz yalnız kendi şubesini çeker).
 * Satırın branch_id'si > kayıt defterindeki üst kayıt (via:sütun) > istek bağlamı > ana şube.
 */
class BranchResolver
{
    /** @var array<string, ?int> "tablo#id" => şube */
    private array $memo = [];

    public function __construct(private readonly SyncSchema $schema) {}

    public function resolve(SyncTable $def, array $attrs): ?int
    {
        if ($def->branch === 'global') {
            return null;
        }
        if ($def->branch === 'self') {
            return isset($attrs['id']) ? (int) $attrs['id'] : null;
        }
        if (array_key_exists('branch_id', $attrs) && $attrs['branch_id'] !== null) {
            return (int) $attrs['branch_id'];
        }
        if (str_starts_with($def->branch, 'via:')) {
            $column = substr($def->branch, 4);
            $target = $this->schema->refs($def->table)[$column] ?? null;
            if ($target && ! str_starts_with($target, 'morph:') && ! empty($attrs[$column])) {
                $branch = $this->branchOf($target, (int) $attrs[$column]);
                if ($branch !== null) {
                    return $branch;
                }
            }
        }

        return app(BranchContext::class)->id() ?? Settings::primaryBranchId();
    }

    public function branchOf(string $table, int $id, int $depth = 0): ?int
    {
        $key = $table.'#'.$id;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $def = SyncRegistry::get($table);
        if (! $def || $depth > 4) {
            return null;
        }
        $columns = $this->schema->columns($table);
        $select = array_values(array_filter(['branch_id', str_starts_with($def->branch, 'via:') ? substr($def->branch, 4) : null],
            fn ($c) => $c !== null && in_array($c, $columns, true)));
        if ($select === []) {
            return $this->memo[$key] = null;
        }
        $row = (array) (DB::table($table)->where('id', $id)->first($select) ?? []);
        if ($row === []) {
            return null;
        }
        if (! empty($row['branch_id'])) {
            return $this->memo[$key] = (int) $row['branch_id'];
        }
        if (str_starts_with($def->branch, 'via:')) {
            $column = substr($def->branch, 4);
            $target = $this->schema->refs($table)[$column] ?? null;
            if ($target && ! str_starts_with($target, 'morph:') && ! empty($row[$column])) {
                return $this->memo[$key] = $this->branchOf($target, (int) $row[$column], $depth + 1);
            }
        }

        return $this->memo[$key] = null;
    }
}
