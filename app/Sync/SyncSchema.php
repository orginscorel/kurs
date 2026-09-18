<?php

namespace App\Sync;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Eşitleme için şema bilgisi: sütunlar, referans (yabancı anahtar) haritası, uuid varlığı.
 * Bilgi önbellekte düz dizi olarak tutulur (serializable_classes=false tuzağı: nesne YOK);
 * migration bittiğinde ve kurs:sync-prepare sonunda temizlenir.
 */
class SyncSchema
{
    private const CACHE_KEY = 'sync.schema.v1';

    /** @var array{tables: list<string>, columns: array<string, list<string>>, fks: array<string, array<string, string>>}|null */
    private ?array $info = null;

    /** @var array<string, array<string, string>> */
    private array $refs = [];

    /** @var array<string, ?string> */
    private static array $morphTables = [];

    /** @var array<string, string> */
    private const CONVENTION = [
        'guidance_teacher_id' => 'teachers', 'advisor_teacher_id' => 'teachers', 'counselor_id' => 'users',
        'financial_guardian_id' => 'guardians', 'homeroom_classroom_id' => 'classrooms', 'makeup_of_id' => 'lesson_sessions',
        'related_invoice_id' => 'invoices', 'reversal_of_id' => 'journal_entries', 'responsible_user_id' => 'users',
        'pos_account_id' => 'finance_accounts', 'bank_account_id' => 'finance_accounts', 'from_account_id' => 'finance_accounts',
        'to_account_id' => 'finance_accounts', 'owner_id' => 'users', 'interested_program_id' => 'programs',
        'incident_id' => 'discipline_incidents', 'meeting_id' => 'discipline_board_meetings', 'behavior_id' => 'discipline_behaviors',
        'sanction_id' => 'discipline_sanctions', 'board_meeting_id' => 'discipline_board_meetings', 'parent_id' => null,
        'assigned_to' => 'users',
    ];

    public function flush(): void
    {
        $this->info = null;
        $this->refs = [];
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{tables: list<string>, columns: array<string, list<string>>, fks: array<string, array<string, string>>} */
    private function info(): array
    {
        if ($this->info !== null) {
            return $this->info;
        }

        $load = function (): array {
            $tables = self::listTables();
            $columns = [];
            $fks = [];
            foreach ($tables as $table) {
                $def = SyncRegistry::get($table);
                if (! $def || ! $def->isSynced()) {
                    continue;
                }
                $columns[$table] = Schema::getColumnListing($table);
                foreach (Schema::getForeignKeys($table) as $fk) {
                    if (count($fk['columns']) === 1) {
                        $fks[$table][$fk['columns'][0]] = $fk['foreign_table'];
                    }
                }
            }

            return ['tables' => $tables, 'columns' => $columns, 'fks' => $fks];
        };

        // Testlerde (bellek içi şema test içinde kurulur) önbelleğe yazma
        if (app()->runningUnitTests()) {
            return $this->info = $load();
        }

        return $this->info = Cache::rememberForever(self::CACHE_KEY, $load);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->info()['tables'];
    }

    public function tableExists(string $table): bool
    {
        return in_array($table, $this->info()['tables'], true);
    }

    /** @var array<string, list<string>> */
    private array $notNullText = [];

    /**
     * Boş bırakılamayan ve varsayılanı olmayan METİN sütunları. Yerel düğümün SQLite şeması bu sütunlarda
     * NULL'a izin verebildiği için cihazdan NULL gelebilir; sunucu bunları boş metne çevirir (bkz. PushService).
     *
     * @return list<string>
     */
    public function notNullTextColumns(string $table): array
    {
        if (! isset($this->notNullText[$table])) {
            $cols = [];
            try {
                foreach (Schema::getColumns($table) as $c) {
                    $type = strtolower((string) ($c['type_name'] ?? ''));
                    if (! ($c['nullable'] ?? true) && ($c['default'] ?? null) === null
                        && in_array($type, ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'string'], true)) {
                        $cols[] = (string) $c['name'];
                    }
                }
            } catch (\Throwable) {
                $cols = [];
            }
            $this->notNullText[$table] = $cols;
        }

        return $this->notNullText[$table];
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return $this->info()['columns'][$table] ?? [];
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    public function hasUuid(string $table): bool
    {
        return $this->hasColumn($table, 'uuid');
    }

    /**
     * Referans sütunları: sütun => hedef tablo | 'morph:<tip sütunu>'.
     *
     * @return array<string, string>
     */
    public function refs(string $table): array
    {
        if (isset($this->refs[$table])) {
            return $this->refs[$table];
        }

        $def = SyncRegistry::get($table);
        $columns = $this->columns($table);
        $schemaFks = $this->info()['fks'][$table] ?? [];
        $out = [];

        foreach ($columns as $column) {
            if ($column === 'id' || $column === 'uuid') {
                continue;
            }
            $target = $this->resolveRef($table, $column, $columns, $schemaFks, $def);
            if ($target !== null) {
                $out[$column] = $target;
            }
        }

        return $this->refs[$table] = $out;
    }

    /**
     * Çözülemeyen *_id sütunları (kayıt defteri testinde "tanımsız referans" hatası).
     *
     * @return list<string>
     */
    public function unresolvedRefs(string $table): array
    {
        $def = SyncRegistry::get($table);
        $refs = $this->refs($table);
        $out = [];
        foreach ($this->columns($table) as $column) {
            if ($column === 'id' || isset($refs[$column]) || array_key_exists($column, $def?->fk ?? [])
                || array_key_exists($column, self::CONVENTION)) {
                continue;
            }
            if (str_ends_with($column, '_id')) {
                $out[] = $column;
            }
        }

        return $out;
    }

    private function resolveRef(string $table, string $column, array $columns, array $schemaFks, ?SyncTable $def): ?string
    {
        if ($def && array_key_exists($column, $def->fk)) {
            return $def->fk[$column];
        }
        if (isset($schemaFks[$column])) {
            return $schemaFks[$column];
        }
        if (array_key_exists($column, self::CONVENTION)) {
            return self::CONVENTION[$column] ?? ($column === 'parent_id' ? $table : null);
        }
        if (str_ends_with($column, '_by')) {
            return 'users';
        }
        if (! str_ends_with($column, '_id')) {
            return null;
        }
        $base = substr($column, 0, -3);
        if (in_array($base.'_type', $columns, true)) {
            return 'morph:'.$base.'_type';
        }
        foreach ([Str::plural($base), $base] as $candidate) {
            if ($this->tableExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Bağlantının kendi veritabanındaki tablolar (MySQL'de başka şemalar karışmasın).
     *
     * @return list<string>
     */
    public static function listTables(): array
    {
        $conn = DB::connection();
        $schema = $conn->getDriverName() === 'sqlite' ? 'main' : $conn->getDatabaseName();

        return array_values(array_unique(Schema::getTableListing($schema, false)));
    }

    /** Morph takma adı → tablo ('student' → 'students'). */
    public static function morphTable(?string $alias): ?string
    {
        if ($alias === null || $alias === '') {
            return null;
        }
        if (array_key_exists($alias, self::$morphTables)) {
            return self::$morphTables[$alias];
        }
        $class = Relation::getMorphedModel($alias) ?? (class_exists($alias) ? $alias : null);

        return self::$morphTables[$alias] = $class ? (new $class)->getTable() : null;
    }

    /**
     * Kayıt defterindeki tablolara eksik eşitleme sütunlarını ekler (idempotent, yalnız ekleme).
     * uuid: boş olabilir + benzersiz indeks. updated_at: değişebilen türlerde yoksa eklenir.
     *
     * @return list<string> yapılan değişiklikler
     */
    public function ensureColumns(): array
    {
        $done = [];
        $existing = self::listTables();

        foreach (SyncRegistry::withUuid() as $table => $def) {
            if (! in_array($table, $existing, true)) {
                continue;
            }
            $cols = Schema::getColumnListing($table);
            $needsUuid = ! in_array('uuid', $cols, true);
            $needsUpdated = ! in_array('updated_at', $cols, true)
                && in_array($def->kind, [SyncRegistry::REFERENCE, SyncRegistry::KEYED], true);

            if (! $needsUuid && ! $needsUpdated) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($needsUuid, $needsUpdated) {
                if ($needsUuid) {
                    $t->uuid('uuid')->nullable();
                }
                if ($needsUpdated) {
                    $t->timestamp('updated_at')->nullable();
                }
            });
            if ($needsUuid) {
                Schema::table($table, fn (Blueprint $t) => $t->unique('uuid', $table.'_uuid_unique'));
                $done[] = "$table.uuid";
            }
            if ($needsUpdated) {
                $done[] = "$table.updated_at";
            }
        }

        $this->flush();

        return $done;
    }

    /**
     * uuid'si boş satırları parça parça doldurur (kilitlenme olmasın diye küçük transaction'lar).
     *
     * @return array<string, int> tablo => doldurulan satır
     */
    public function backfillUuids(int $chunk = 500, ?callable $progress = null): array
    {
        $out = [];
        if (DB::connection()->getDriverName() === 'sqlite') {
            $chunk = min($chunk, 300);   // SQLite 3.26: en fazla 999 bağlı değişken
        }
        foreach (SyncRegistry::withUuid() as $table => $def) {
            if (! $this->hasUuid($table)) {
                continue;
            }
            $count = 0;
            while (true) {
                $ids = DB::table($table)->whereNull('uuid')->orderBy('id')->limit($chunk)->pluck('id')->all();
                if ($ids === []) {
                    break;
                }
                $cases = [];
                $bindings = [];
                foreach ($ids as $id) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $id;
                    $bindings[] = (string) Str::uuid7();
                }
                $in = implode(',', array_fill(0, count($ids), '?'));
                $grammar = DB::getQueryGrammar();
                DB::transaction(fn () => DB::update(
                    'UPDATE '.$grammar->wrapTable($table).' SET '.$grammar->wrap('uuid').' = CASE '.$grammar->wrap('id').' '.implode(' ', $cases).' END WHERE '.$grammar->wrap('id')." IN ($in) AND ".$grammar->wrap('uuid').' IS NULL',
                    [...$bindings, ...$ids],
                ));
                $count += count($ids);
                if ($progress) {
                    $progress($table, $count);
                }
            }
            if ($count > 0) {
                $out[$table] = $count;
            }
        }

        return $out;
    }
}
