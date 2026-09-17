<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Satır ↔ tel biçimi. Yerel ve sunucu tamsayı kimlikleri farklıdır; referans sütunları
 * tel üzerinde uuid taşır, alıcı kendi kimliğine çevirir. Eşitlenmeyen tabloya referans veren
 * sütunlar tel üzerinde hiç gönderilmez (alıcının değeri korunur).
 */
class RowCodec
{
    /** @var array<string, array<int|string, ?string>> tablo => id => uuid */
    private array $uuidById = [];

    /** @var array<string, array<string, ?int>> tablo => uuid => id */
    private array $idByUuid = [];

    /** Pivot satır kimliği için sabit ad alanı */
    private const PIVOT_NS = '6f1c3a52-9d0e-4f7b-9a57-5b3f2b9e1c11';

    public function __construct(private readonly SyncSchema $schema) {}

    public function forget(): void
    {
        $this->uuidById = [];
        $this->idByUuid = [];
    }

    /** Ham değer normalizasyonu (hash ve tel için tek biçim). */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    /**
     * Satır özniteliklerini tel biçimine çevirir.
     *
     * @param array<string, mixed> $attrs ham sütun değerleri
     * @param bool $assignMissing referans verilen satırın uuid'i boşsa hemen atansın mı
     * @return array<string, mixed>
     */
    public function encode(string $table, array $attrs, bool $assignMissing = true): array
    {
        $def = SyncRegistry::get($table);
        $refs = $this->schema->refs($table);
        $out = [];

        foreach ($attrs as $column => $value) {
            if ($column === 'id' || $column === 'uuid' || ($def && $def->excludes($column))) {
                continue;
            }
            if (! isset($refs[$column])) {
                $out[$column] = self::normalize($value);

                continue;
            }
            $target = $this->targetTable($refs[$column], $attrs);
            if ($target === null || ! $this->isRefSynced($target)) {
                continue;   // eşitlenmeyen tabloya referans: gönderme
            }
            $out[$column] = $value === null || $value === '' ? null : $this->uuidFor($target, $value, $assignMissing);
        }

        return $out;
    }

    /**
     * Tel biçimindeki alanları yerel kimliklere çevirir.
     *
     * @param array<string, mixed> $fields
     * @return array{0: array<string, mixed>, 1: list<array{column: string, table: string, uuid: string}>} çözülen alanlar + çözülemeyen referanslar
     */
    public function decode(string $table, array $fields): array
    {
        $refs = $this->schema->refs($table);
        $columns = $this->schema->columns($table);
        $def = SyncRegistry::get($table);
        $out = [];
        $missing = [];

        foreach ($fields as $column => $value) {
            if ($column === 'id' || $column === 'uuid' || ! in_array($column, $columns, true) || ($def && $def->excludes($column))) {
                continue;
            }
            if (! isset($refs[$column])) {
                $out[$column] = $value;

                continue;
            }
            $target = $this->targetTable($refs[$column], $fields);
            if ($target === null || ! $this->isRefSynced($target)) {
                continue;
            }
            if ($value === null || $value === '') {
                $out[$column] = null;

                continue;
            }
            $id = $this->idFor($target, (string) $value);
            if ($id === null) {
                $missing[] = ['column' => $column, 'table' => $target, 'uuid' => (string) $value];

                continue;
            }
            $out[$column] = $id;
        }

        return [$out, $missing];
    }

    /** Referans hedef tablosu; morph için tip sütunundaki takma addan. */
    public function targetTable(string $ref, array $row): ?string
    {
        if (str_starts_with($ref, 'morph:')) {
            $typeColumn = substr($ref, 6);

            return SyncSchema::morphTable($row[$typeColumn] ?? null);
        }

        return $ref;
    }

    private function isRefSynced(string $table): bool
    {
        $def = SyncRegistry::get($table);

        return $def !== null && $def->hasUuid() && $this->schema->hasUuid($table);
    }

    public function uuidFor(string $table, int|string $id, bool $assignMissing = true): ?string
    {
        if (isset($this->uuidById[$table][$id])) {
            return $this->uuidById[$table][$id];
        }
        $uuid = DB::table($table)->where('id', $id)->value('uuid');
        if ($uuid === null && $assignMissing && DB::table($table)->where('id', $id)->exists()) {
            // DB::table ile olaysız eklenmiş satır: kimliği şimdi ver (süpürücü de aynısını yapar)
            $uuid = app(SyncContext::class)->nextUuid($table);
            DB::table($table)->where('id', $id)->whereNull('uuid')->update(['uuid' => $uuid]);
            $uuid = DB::table($table)->where('id', $id)->value('uuid');
        }
        if ($uuid !== null) {
            $this->uuidById[$table][$id] = $uuid;
            $this->idByUuid[$table][$uuid] = (int) $id;
        }

        return $uuid;
    }

    public function idFor(string $table, string $uuid): ?int
    {
        if (isset($this->idByUuid[$table][$uuid])) {
            return $this->idByUuid[$table][$uuid];
        }
        $id = DB::table($table)->where('uuid', $uuid)->value('id');
        $id = $id === null ? null : (int) $id;
        if ($id !== null) {
            $this->idByUuid[$table][$uuid] = $id;
            $this->uuidById[$table][$id] = $uuid;
        }

        return $id;
    }

    public function remember(string $table, int $id, string $uuid): void
    {
        $this->idByUuid[$table][$uuid] = $id;
        $this->uuidById[$table][$id] = $uuid;
    }

    /**
     * Pivot satır kimliği: anahtar sütunlarının (uuid'ye çevrilmiş) değerlerinden sabit uuid.
     *
     * @param array<string, mixed> $encodedKey
     */
    public static function pivotUuid(string $table, array $encodedKey): string
    {
        ksort($encodedKey);

        return \Ramsey\Uuid\Uuid::uuid5(self::PIVOT_NS, $table.'|'.json_encode($encodedKey))->toString();
    }

    /** Satırın kararlı özeti (süpürücü). */
    public static function hash(array $row): string
    {
        ksort($row);

        return hash('xxh128', json_encode(array_map([self::class, 'normalize'], $row), JSON_UNESCAPED_UNICODE));
    }
}
