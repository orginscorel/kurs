<?php

namespace App\Sync\Commands;

use App\Sync\RowCodec;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Komut argümanlarında kimlik çevirisi. Şema: nokta yolu => tablo ('data.installment_ids' => 'installments[]').
 * Yol '*' içerebilir ('rows.*.id' => 'installments'): dizideki her öğe için uygulanır.
 * Yerelde id → uuid, sunucuda uuid → id.
 */
class CommandCodec
{
    public function __construct(private readonly RowCodec $codec, private readonly SyncSchema $schema) {}

    /** @param array<string, string> $spec */
    public function encode(array $args, array $spec): array
    {
        foreach ($spec as $pattern => $table) {
            $many = str_ends_with($table, '[]');
            $table = rtrim($table, '[]');
            foreach (self::expand($args, $pattern) as $path) {
                if (! Arr::has($args, $path)) {
                    continue;
                }
                $value = Arr::get($args, $path);
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                $encode = fn ($id) => $this->codec->uuidFor($table, (int) $id);
                Arr::set($args, $path, $many ? array_values(array_map($encode, (array) $value)) : $encode($value));
            }
        }

        return $args;
    }

    /**
     * @param array<string, string> $spec
     * @return array{0: array, 1: list<string>} çözülmüş argümanlar + bulunamayan referanslar
     */
    public function decode(array $args, array $spec): array
    {
        $missing = [];
        foreach ($spec as $pattern => $table) {
            $many = str_ends_with($table, '[]');
            $table = rtrim($table, '[]');
            foreach (self::expand($args, $pattern) as $path) {
                if (! Arr::has($args, $path)) {
                    continue;
                }
                $value = Arr::get($args, $path);
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                $decode = function ($uuid) use ($table, &$missing, $path) {
                    $id = is_string($uuid) ? $this->codec->idFor($table, $uuid) : null;
                    if ($id === null) {
                        $missing[] = $path.' → '.$table.':'.(is_string($uuid) ? $uuid : '?');
                    }

                    return $id;
                };
                Arr::set($args, $path, $many ? array_values(array_map($decode, (array) $value)) : $decode($value));
            }
        }

        return [$args, $missing];
    }

    /**
     * 'rows.*.id' → ['rows.0.id', 'rows.1.id', …] (argümandaki gerçek anahtarlarla).
     *
     * @return list<string>
     */
    public static function expand(array $args, string $pattern): array
    {
        $pos = strpos($pattern, '*');
        if ($pos === false) {
            return [$pattern];
        }
        $prefix = rtrim(substr($pattern, 0, $pos), '.');
        $rest = ltrim(substr($pattern, $pos + 1), '.');
        $list = $prefix === '' ? $args : Arr::get($args, $prefix);
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach (array_keys($list) as $key) {
            $base = ($prefix === '' ? '' : $prefix.'.').$key;
            foreach ($rest === '' ? [$base] : self::expand($args, $base.'.'.$rest) as $p) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Komut boyunca olaysız (DB::table) eklenmiş, uuid'siz satırlara sırayla uuid verir.
     * Yerelde yakalamaya, sunucuda hazır listeden → iki tarafta aynı satır aynı uuid'i alır.
     *
     * @return array<string, int> tablo => komut öncesi en büyük id
     */
    public function maxIds(): array
    {
        $out = [];
        foreach (SyncRegistry::withUuid() as $table => $def) {
            if ($this->schema->hasUuid($table)) {
                $out[$table] = (int) (DB::table($table)->max('id') ?? 0);
            }
        }

        return $out;
    }

    /** @param array<string, int> $before */
    public function assignMissingUuids(array $before): void
    {
        $ctx = app(\App\Sync\SyncContext::class);
        foreach ($before as $table => $max) {
            $ids = DB::table($table)->where('id', '>', $max)->whereNull('uuid')->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $uuid = $ctx->nextUuid($table);
                DB::table($table)->where('id', $id)->update(['uuid' => $uuid]);
                $this->codec->remember($table, (int) $id, $uuid);
            }
        }
    }
}
