<?php

namespace App\Sync\Server;

use App\Exceptions\BusinessRuleException;
use App\Sync\BranchResolver;
use App\Sync\ChangeRecorder;
use App\Sync\Commands\CommandCodec;
use App\Sync\Commands\CommandRegistry;
use App\Sync\ModelMap;
use App\Sync\Models\SyncDevice;
use App\Sync\RowCodec;
use App\Sync\Sweeper;
use App\Sync\SyncContext;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use App\Sync\SyncTable;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cihazın değişiklik paketini uygular.
 *  - Tek transaction; her değişiklik kendi kayıt noktasında (savepoint): biri reddedilirse diğerleri işlenir.
 *  - İdempotent: değişiklik uuid'i sync_receipts'te; tekrar gelirse ilk sonuç döner.
 *  - Satır değişikliği: alan bazında son yazan kazanır, çakışma kaydı; yalnız itilebilir tablolar.
 *  - Komut: sunucu servisiyle yeniden yürütülür (finans). Ham finans satırı ASLA kabul edilmez.
 */
class PushService
{
    public function __construct(
        private readonly SyncContext $context,
        private readonly SyncSchema $schema,
        private readonly RowCodec $codec,
        private readonly ChangeRecorder $recorder,
        private readonly Sweeper $sweeper,
        private readonly CommandCodec $commandCodec,
        private readonly ConflictService $conflicts,
        private readonly ServerHooks $hooks,
        private readonly BranchResolver $branches,
    ) {}

    /**
     * @param list<array<string, mixed>> $changes
     * @return array{results: list<array<string, mixed>>, cursor: int, accepted: int, rejected: int, conflicts: int, duplicates: int}
     */
    public function push(SyncDevice $device, array $changes, ?int $baseCursor, ?string $deviceTime): array
    {
        $skew = 0.0;
        if ($deviceTime) {
            try {
                $skew = (float) (CarbonImmutable::now()->getTimestampMs() - CarbonImmutable::parse($deviceTime)->getTimestampMs()) / 1000;
            } catch (\Throwable) {
                $skew = 0.0;
            }
        }
        $baseTime = $baseCursor ? DB::table('sync_changes')->where('id', '<=', $baseCursor)->orderByDesc('id')->value('created_at') : null;

        $results = [];
        $stats = ['accepted' => 0, 'rejected' => 0, 'conflicts' => 0, 'duplicates' => 0];

        app(BranchContext::class)->run((int) $device->branch_id, function () use ($device, $changes, $baseCursor, $baseTime, $skew, &$results, &$stats) {
            DB::transaction(function () use ($device, $changes, $baseCursor, $baseTime, $skew, &$results, &$stats) {
                foreach ($changes as $change) {
                    $id = (string) ($change['id'] ?? '');
                    $receipt = $id !== '' ? DB::table('sync_receipts')->where('change_uuid', $id)->first() : null;
                    if ($receipt) {
                        $prev = json_decode((string) $receipt->result, true) ?: [];
                        $results[] = ['id' => $id, 'status' => 'duplicate', 'previous' => $receipt->status] + array_intersect_key($prev, array_flip(['row', 'unused_uuids']));
                        $stats['duplicates']++;

                        continue;
                    }

                    try {
                        $result = DB::transaction(fn () => isset($change['command'])
                            ? $this->applyCommand($device, $change, $baseTime, $skew)
                            : $this->applyRow($device, $change, $baseCursor, $skew));
                    } catch (BusinessRuleException $e) {
                        $result = ['status' => 'rejected', 'code' => $e->errorCode, 'message' => $e->getMessage()];
                    } catch (ValidationException $e) {
                        $result = ['status' => 'rejected', 'code' => 'validation_failed', 'message' => collect($e->errors())->flatten()->first() ?? 'Doğrulama hatası.'];
                    } catch (SyncReject $e) {
                        $result = ['status' => 'rejected', 'code' => $e->errorCode, 'message' => $e->getMessage()];
                    } catch (\Illuminate\Database\QueryException $e) {
                        report($e);
                        $result = ['status' => 'rejected', 'code' => 'database_error', 'message' => 'Kayıt sunucu veritabanı kurallarına uymadı.'];
                    }

                    $result['id'] = $id;
                    if ($result['status'] === 'rejected') {
                        $stats['rejected']++;
                        $this->conflicts->rejected($device, $change, $result);
                    } elseif (($result['conflicts'] ?? 0) > 0) {
                        $stats['conflicts']++;
                        $stats['accepted']++;
                    } else {
                        $stats['accepted']++;
                    }
                    if ($id !== '') {
                        DB::table('sync_receipts')->insert([
                            'change_uuid' => $id, 'device_id' => $device->id, 'status' => $result['status'],
                            'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
                        ]);
                    }
                    $results[] = $result;
                }

                $device->forceFill([
                    'last_push_at' => now(), 'last_seen_at' => now(),
                    'rejected_total' => $device->rejected_total + $stats['rejected'],
                ])->save();
            });
        });

        return ['results' => $results, 'cursor' => (int) (DB::table('sync_changes')->max('id') ?? 0)] + $stats;
    }

    // ================================================================== satır

    /** @return array<string, mixed> */
    public function applyRow(SyncDevice $device, array $c, ?int $baseCursor, float $skew): array
    {
        $table = (string) ($c['table'] ?? '');
        $def = SyncRegistry::get($table);
        if (! $def || ! $def->isPushable() || ! $this->schema->tableExists($table)) {
            throw new SyncReject('Bu tablo cihazdan değiştirilemez: '.$table, 'not_pushable');
        }
        $op = (string) ($c['op'] ?? '');
        $rowUuid = (string) ($c['row'] ?? '');
        if (! in_array($op, ['insert', 'update', 'delete', 'upsert'], true) || ! preg_match('/^[0-9a-f-]{36}$/i', $rowUuid)) {
            throw new SyncReject('Geçersiz değişiklik biçimi.', 'invalid_change');
        }
        $fields = is_array($c['fields'] ?? null) ? $c['fields'] : [];
        $clientAt = $this->clientTime($c['at'] ?? null, $skew);

        if ($def->isPivot()) {
            return $this->applyPivot($device, $def, $op, $rowUuid, $fields, $c);
        }

        [$decoded, $missing] = $this->codec->decode($table, $fields);
        if ($missing !== []) {
            throw new SyncReject('Bağlı kayıt sunucuda bulunamadı ('.$missing[0]['table'].').', 'missing_reference');
        }
        if (array_key_exists('branch_id', $decoded) || $this->schema->hasColumn($table, 'branch_id')) {
            if ($this->schema->hasColumn($table, 'branch_id') && in_array($op, ['insert', 'upsert'], true)) {
                $decoded['branch_id'] = $device->branch_id;
            } else {
                unset($decoded['branch_id']);
            }
        }

        $existing = $this->find($table, $rowUuid);
        $alias = null;

        if ($def->kind === SyncRegistry::UPLOAD) {
            if ($existing) {
                return ['status' => 'accepted', 'row' => $rowUuid, 'note' => 'zaten var'];
            }
            $this->insertRaw($def, $rowUuid, $decoded);

            return ['status' => 'accepted', 'row' => $rowUuid];
        }

        if (! $existing && in_array($op, ['insert', 'upsert'], true) && $def->key !== []) {
            $match = $this->findByKey($def, $decoded);
            if ($match) {
                $existing = $match;
                $alias = $rowUuid;
                $rowUuid = (string) $match['uuid'];
                if ($def->kind === SyncRegistry::APPEND) {
                    return ['status' => 'accepted', 'row' => $rowUuid, 'alias' => $alias, 'note' => 'aynı olay zaten kayıtlı'];
                }
            }
        }

        if ($existing && ! $this->belongsToDevice($device, $def, $existing)) {
            throw new SyncReject('Kayıt bu cihazın şubesine ait değil.', 'branch_mismatch');
        }

        if ($op === 'delete') {
            return $this->applyDelete($device, $def, $existing, $rowUuid, $baseCursor, $clientAt, $c);
        }

        if (! $existing) {
            if ($op === 'update') {
                $tomb = DB::table('sync_tombstones')->where('table_name', $table)->where('row_uuid', $rowUuid)->exists();
                if ($tomb) {
                    $this->conflicts->record($device, [
                        'kind' => 'delete', 'table_name' => $table, 'row_uuid' => $rowUuid, 'field' => null,
                        'device_value' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'server_value' => 'silindi',
                        'device_at' => $clientAt, 'winner' => 'server', 'change_uuid' => $c['id'] ?? null,
                        'note' => 'Cihaz, sunucuda silinmiş bir kaydı güncelledi; kayıt silinmiş olarak kaldı.',
                    ]);

                    return ['status' => 'conflict', 'row' => $rowUuid, 'conflicts' => 1, 'message' => 'Kayıt sunucuda silinmiş.'];
                }
                throw new SyncReject('Güncellenecek kayıt sunucuda yok.', 'row_missing');
            }

            if ($def->kind === SyncRegistry::KEYED || $def->kind === SyncRegistry::REFERENCE || $def->kind === SyncRegistry::APPEND) {
                $id = $this->insertModel($def, $rowUuid, $decoded);
                $row = (array) DB::table($table)->where('id', $id)->first();
                $this->recordApplied($device, $def, $rowUuid, 'insert', $this->codec->encode($table, $row), $row, $c, false);
                $this->sweeper->refresh($def, ['id' => $id]);
                $this->hooks->afterInsert($def, $id);

                return ['status' => 'accepted', 'row' => $rowUuid];
            }
            throw new SyncReject('Bu tabloya cihazdan ekleme yapılamaz.', 'not_pushable');
        }

        // ---- güncelleme (ya da mevcut satıra ekleme/birleştirme): alan bazında son yazan kazanır
        if ($def->kind === SyncRegistry::APPEND) {
            return ['status' => 'accepted', 'row' => $rowUuid, 'note' => 'yalnız ekleme'];
        }
        $wire = array_intersect_key($fields, $decoded);
        [$apply, $conflictCount, $deviceWon] = $this->resolveFields($device, $def, $rowUuid, $wire, $existing, $baseCursor, $clientAt, $c);
        $applyDecoded = array_intersect_key($decoded, $apply);

        $applyDecoded = $this->hooks->beforeUpdate($def, (int) $existing['id'], $applyDecoded);
        if ($applyDecoded !== []) {
            $this->updateModel($def, (int) $existing['id'], $applyDecoded);
            $row = (array) DB::table($table)->where('id', $existing['id'])->first();
            $applied = array_intersect_key($this->codec->encode($table, $row), $applyDecoded);
            $this->recordApplied($device, $def, $rowUuid, 'update', $applied, $row, $c, $deviceWon || $alias !== null);
            $this->sweeper->refresh($def, ['id' => $existing['id']]);
        }
        $this->hooks->afterUpdate($def, (int) $existing['id']);

        if ($alias !== null) {
            // Cihazın kendi uuid'i sunucudaki kayda bağlandı: cihaz yerel satırını bu uuid'e taşısın
            $this->recorder->write($table, $rowUuid, 'merge', ['alias' => $alias], (int) ($existing['branch_id'] ?? $device->branch_id) ?: null, [
                'source' => 'device', 'origin_device_id' => $device->id, 'echo' => true,
            ]);
        }

        return ['status' => $conflictCount > 0 ? 'conflict' : 'accepted', 'row' => $rowUuid, 'conflicts' => $conflictCount]
            + ($alias ? ['alias' => $alias] : []);
    }

    /**
     * @return array{0: array<string, mixed>, 1: int, 2: bool} uygulanacak alanlar, çakışma sayısı, cihaz kazandı mı
     */
    private function resolveFields(SyncDevice $device, SyncTable $def, string $rowUuid, array $wire, array $existing, ?int $baseCursor, CarbonImmutable $clientAt, array $c): array
    {
        $serverChanges = DB::table('sync_changes')
            ->where('table_name', $def->table)->where('row_uuid', $rowUuid)
            ->where('id', '>', (int) $baseCursor)
            ->whereIn('op', ['insert', 'update', 'upsert'])
            ->where(fn ($q) => $q->whereNull('origin_device_id')->orWhere('origin_device_id', '!=', $device->id))
            ->orderBy('id')->get(['id', 'fields', 'created_at']);

        $latest = [];
        foreach ($serverChanges as $sc) {
            foreach ((array) json_decode((string) $sc->fields, true) as $k => $v) {
                $latest[$k] = ['id' => (int) $sc->id, 'at' => $sc->created_at, 'value' => $v];
            }
        }

        $current = $this->codec->encode($def->table, $existing);
        $apply = [];
        $conflicts = 0;
        $deviceWon = false;
        foreach ($wire as $field => $value) {
            if ($field === 'updated_at') {
                continue;
            }
            $serverValue = $current[$field] ?? null;
            if ($this->same($serverValue, $value)) {
                continue;   // değişiklik yok
            }
            if (! isset($latest[$field])) {
                $apply[$field] = $value;

                continue;
            }
            $serverAt = CarbonImmutable::parse($latest[$field]['at']);
            $winner = $clientAt->greaterThanOrEqualTo($serverAt) ? 'device' : 'server';
            $conflicts++;
            $this->conflicts->record($device, [
                'kind' => $def->kind === SyncRegistry::KEYED ? 'attendance' : 'field',
                'table_name' => $def->table, 'row_uuid' => $rowUuid, 'row_id' => (int) $existing['id'], 'field' => $field,
                'device_value' => $this->stringify($value), 'server_value' => $this->stringify($serverValue),
                'device_at' => $clientAt, 'server_at' => $serverAt, 'winner' => $winner,
                'change_uuid' => $c['id'] ?? null, 'server_change_id' => $latest[$field]['id'],
                'note' => $winner === 'device'
                    ? 'Son yazan kazanır: cihazdaki değer daha yeni, uygulandı. Önceki sunucu değeri tarihçede.'
                    : 'Son yazan kazanır: sunucudaki değer daha yeni, korundu.',
            ]);
            if ($winner === 'device') {
                $apply[$field] = $value;
                $deviceWon = true;
            }
        }
        if ($apply !== [] && isset($wire['updated_at'])) {
            $apply['updated_at'] = $wire['updated_at'];
        }

        return [$apply, $conflicts, $deviceWon];
    }

    private function applyDelete(SyncDevice $device, SyncTable $def, ?array $existing, string $rowUuid, ?int $baseCursor, CarbonImmutable $clientAt, array $c): array
    {
        if (! $existing) {
            return ['status' => 'accepted', 'row' => $rowUuid, 'note' => 'zaten silinmiş'];
        }
        $changedAfter = DB::table('sync_changes')->where('table_name', $def->table)->where('row_uuid', $rowUuid)
            ->where('id', '>', (int) $baseCursor)
            ->where(fn ($q) => $q->whereNull('origin_device_id')->orWhere('origin_device_id', '!=', $device->id))
            ->whereIn('op', ['insert', 'update', 'upsert'])->max('id');
        if ($changedAfter) {
            $this->conflicts->record($device, [
                'kind' => 'delete', 'table_name' => $def->table, 'row_uuid' => $rowUuid, 'row_id' => (int) $existing['id'],
                'device_value' => 'sil', 'server_value' => 'güncellendi', 'device_at' => $clientAt, 'winner' => 'server',
                'change_uuid' => $c['id'] ?? null, 'server_change_id' => (int) $changedAfter,
                'note' => 'Cihaz kaydı sildi ama kayıt sunucuda sonradan güncellenmişti; kayıt korundu ve cihaza geri gönderildi.',
            ]);
            $this->recorder->write($def->table, $rowUuid, 'upsert', $this->codec->encode($def->table, $existing), $this->branches->resolve($def, $existing), [
                'source' => 'conflict', 'origin_device_id' => $device->id, 'echo' => true,
            ]);

            return ['status' => 'conflict', 'row' => $rowUuid, 'conflicts' => 1];
        }

        $class = ModelMap::classFor($def->table);
        $this->context->applying(function () use ($class, $def, $existing) {
            if ($class) {
                $model = $class::query()->withoutGlobalScopes()->find($existing['id']);
                $model?->delete();   // SoftDeletes varsa yumuşak silme
            } else {
                DB::table($def->table)->where('id', $existing['id'])->delete();
            }
        }, $device->id);

        $after = DB::table($def->table)->where('id', $existing['id'])->first();
        if ($after && property_exists($after, 'deleted_at')) {
            $this->recordApplied($device, $def, $rowUuid, 'update', ['deleted_at' => RowCodec::normalize($after->deleted_at)], (array) $after, $c, false);
            $this->sweeper->refresh($def, ['id' => $existing['id']]);
        } else {
            $this->recordApplied($device, $def, $rowUuid, 'delete', null, $existing, $c, false);
            $this->sweeper->forget($def, (string) $existing['id']);
        }

        return ['status' => 'accepted', 'row' => $rowUuid];
    }

    private function applyPivot(SyncDevice $device, SyncTable $def, string $op, string $rowUuid, array $fields, array $c): array
    {
        $keyFields = array_intersect_key($fields, array_flip($def->key));
        [$decoded, $missing] = $this->codec->decode($def->table, $fields);
        if ($missing !== [] || count(array_intersect_key($decoded, array_flip($def->key))) !== count($def->key)) {
            throw new SyncReject('Ara tablo kaydının bağlı kayıtları sunucuda yok.', 'missing_reference');
        }
        $key = array_intersect_key($decoded, array_flip($def->key));
        $canonical = RowCodec::pivotUuid($def->table, $keyFields);

        if ($op === 'delete') {
            DB::table($def->table)->where($key)->delete();
            $this->sweeper->forget($def, implode('|', array_map(fn ($k) => (string) $key[$k], $def->key)));
        } else {
            DB::table($def->table)->insertOrIgnore($decoded);
            $this->sweeper->refresh($def, $key);
        }
        $this->recorder->write($def->table, $canonical, $op === 'delete' ? 'delete' : 'insert', $keyFields + $this->codec->encode($def->table, $decoded),
            $this->branches->resolve($def, $decoded), [
                'change_uuid' => $c['id'] ?? (string) \Illuminate\Support\Str::uuid7(),
                'source' => 'device', 'origin_device_id' => $device->id,
            ]);

        return ['status' => 'accepted', 'row' => $canonical];
    }

    // ================================================================== komut

    private function applyCommand(SyncDevice $device, array $c, ?string $baseTime, float $skew): array
    {
        $name = (string) $c['command'];
        $def = CommandRegistry::get($name) ?? throw new SyncReject('Tanımsız işlem: '.$name, 'unknown_command');
        $args = is_array($c['args'] ?? null) ? $c['args'] : [];
        [$decodedArgs, $missing] = $this->commandCodec->decode($args, $def['spec']);
        if ($missing !== []) {
            throw new SyncReject('İşlemin bağlı kaydı sunucuda bulunamadı: '.$missing[0], 'missing_reference');
        }

        $uuids = is_array($c['uuids'] ?? null) ? $c['uuids'] : [];
        $numbers = array_intersect_key(is_array($c['numbers'] ?? null) ? $c['numbers'] : [],
            array_flip([...\App\Sync\SyncNumbers::DEVICE_DOCUMENTS, ...\App\Sync\SyncNumbers::LEASABLE]));
        // Cihazın vereceği uuid sunucuda başka satırda varsa (bozuk paket) kabul etme
        foreach ($uuids as $table => $list) {
            if (! is_string($table) || ! SyncRegistry::get($table)?->hasUuid() || ! $this->schema->hasUuid($table)) {
                unset($uuids[$table]);

                continue;
            }
            $taken = DB::table($table)->whereIn('uuid', (array) $list)->pluck('uuid')->all();
            if ($taken !== [] && $table === $def['root']) {
                // Aynı cihazın daha önce yürütülmüş komutu farklı değişiklik kimliğiyle yeniden geldiyse: tekrar yürütme
                $earlier = DB::table('sync_changes')->where('table_name', $table)->whereIn('row_uuid', $taken)
                    ->whereNotNull('command_uuid')->pluck('command_uuid')->unique()->all();
                if ($earlier !== [] && DB::table('sync_receipts')->whereIn('change_uuid', $earlier)->where('device_id', $device->id)->exists()) {
                    return ['status' => 'duplicate', 'previous' => 'accepted', 'row' => $taken[0], 'note' => 'Bu işlem daha önce yürütülmüş.'];
                }
                throw new SyncReject('İşlem kimliği sunucuda başka bir kayıtta kullanılmış ('.$table.').', 'uuid_collision');
            }
            if ($taken !== []) {
                $uuids[$table] = array_values(array_diff((array) $list, $taken));   // zaten var olan (katalog) satırlar
            }
            if (! in_array($table, CommandRegistry::produces($name), true)) {
                unset($uuids[$table]);
            }
        }

        $handler = app($def['handler'][0]);
        $method = $def['handler'][1];
        $reconcile = function ($model, string $note) use ($device, $def, $c) {
            $table = $model instanceof \Illuminate\Database\Eloquent\Model ? $model->getTable() : $def['root'];
            $this->conflicts->record($device, [
                'kind' => 'finance', 'table_name' => $table, 'row_uuid' => $model->getAttribute('uuid'), 'row_id' => (int) $model->getKey(),
                'device_value' => $def['label'], 'server_value' => null, 'winner' => 'both',
                'change_uuid' => $c['id'] ?? null, 'note' => $note.' Mutabakat bekliyor.',
            ]);
        };
        $extraUnused = [];
        $markUnused = function (string $table, array $list) use (&$extraUnused) {
            $extraUnused[$table] = array_values(array_unique([...($extraUnused[$table] ?? []), ...$list]));
        };

        $prevCommand = $this->context->originCommand;
        $this->context->originCommand = (string) ($c['id'] ?? '');
        $this->context->takeNegativeCashNotes();
        try {
            $before = $this->commandCodec->maxIds();
            [$model, $unused] = $this->context->withPresets($uuids, $numbers, function () use ($handler, $method, $decodedArgs, $baseTime, $reconcile, $markUnused, $before) {
                $model = $handler->{$method}($decodedArgs, ['base_time' => $baseTime, 'reconcile' => $reconcile, 'unused' => $markUnused]);
                $this->commandCodec->assignMissingUuids($before);

                return $model;
            });
            if ($cash = $this->context->takeNegativeCashNotes()) {
                $reconcile($model, implode(' ', $cash));
            }
        } finally {
            $this->context->originCommand = $prevCommand;
        }
        foreach ($extraUnused as $table => $list) {
            $unused[$table] = array_values(array_unique([...($unused[$table] ?? []), ...$list]));
        }

        $rowUuid = $model->getAttribute('uuid') ?: DB::table($model->getTable())->where('id', $model->getKey())->value('uuid');
        $conflicts = DB::table('sync_conflicts')->where('change_uuid', $c['id'] ?? '')->count();

        return array_filter([
            'status' => $conflicts > 0 ? 'conflict' : 'accepted',
            'row' => $rowUuid,
            'conflicts' => $conflicts,
            'unused_uuids' => $unused ?: null,
            'document_no' => collect(['receipt_no', 'enrollment_no', 'refund_no', 'note_no'])
                ->map(fn ($k) => $model->getAttributes()[$k] ?? null)->filter()->first(),
        ], fn ($v) => $v !== null);
    }

    // ================================================================== yardımcılar

    private function belongsToDevice(SyncDevice $device, SyncTable $def, array $row): bool
    {
        if ($def->branch === 'global') {
            return true;
        }
        $branch = $this->branches->resolve($def, $row);

        return $branch === null || (int) $branch === (int) $device->branch_id;
    }

    private function find(string $table, string $uuid): ?array
    {
        $row = DB::table($table)->where('uuid', $uuid)->first();

        return $row ? (array) $row : null;
    }

    private function findByKey(SyncTable $def, array $decoded): ?array
    {
        $where = [];
        foreach ($def->key as $k) {
            if (! array_key_exists($k, $decoded) || $decoded[$k] === null || $decoded[$k] === '') {
                return null;
            }
            $where[$k] = $decoded[$k];
        }
        $q = DB::table($def->table)->where($where);
        if ($this->schema->hasColumn($def->table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        $row = $q->orderBy('id')->first();

        return $row ? (array) $row : null;
    }

    private function insertModel(SyncTable $def, string $uuid, array $decoded): int
    {
        $class = ModelMap::classFor($def->table);
        $decoded['uuid'] = $uuid;

        return (int) $this->context->applying(function () use ($class, $def, $decoded) {
            if ($class) {
                /** @var \Illuminate\Database\Eloquent\Model $model */
                $model = new $class;
                $model->setRawAttributes($decoded);
                if ($model->usesTimestamps()) {
                    $model->timestamps = ! isset($decoded['created_at']);
                }
                $model->save();

                return $model->getKey();
            }

            return $this->insertRaw($def, $decoded['uuid'], $decoded);
        });
    }

    private function insertRaw(SyncTable $def, string $uuid, array $decoded): int
    {
        $decoded['uuid'] = $uuid;
        $cols = $this->schema->columns($def->table);
        $decoded = array_intersect_key($decoded, array_flip($cols));

        return (int) DB::table($def->table)->insertGetId($decoded);
    }

    private function updateModel(SyncTable $def, int $id, array $decoded): void
    {
        $class = ModelMap::classFor($def->table);
        $this->context->applying(function () use ($class, $def, $id, $decoded) {
            if ($class) {
                $query = $class::query()->withoutGlobalScopes();
                /** @var \Illuminate\Database\Eloquent\Model|null $model */
                $model = $query->find($id);
                if ($model) {
                    $model->setRawAttributes(array_merge($model->getAttributes(), $decoded));
                    if (isset($decoded['updated_at'])) {
                        $model->timestamps = false;
                    }
                    $model->save();

                    return;
                }
            }
            DB::table($def->table)->where('id', $id)->update($decoded);
        });
    }

    private function recordApplied(SyncDevice $device, SyncTable $def, string $rowUuid, string $op, ?array $fields, array $row, array $c, bool $echo): void
    {
        $this->recorder->write($def->table, $rowUuid, $op, $fields, $this->branches->resolve($def, $row), [
            'change_uuid' => $c['id'] ?? (string) \Illuminate\Support\Str::uuid7(),
            'source' => 'device', 'origin_device_id' => $device->id, 'echo' => $echo,
            'client_at' => isset($c['at']) ? CarbonImmutable::parse($c['at'])->format('Y-m-d H:i:s.v') : null,
        ]);
    }

    private function clientTime(?string $at, float $skew): CarbonImmutable
    {
        try {
            $t = $at ? CarbonImmutable::parse($at) : CarbonImmutable::now();
        } catch (\Throwable) {
            $t = CarbonImmutable::now();
        }
        $t = $t->addMilliseconds((int) round($skew * 1000));

        return $t->greaterThan(CarbonImmutable::now()) ? CarbonImmutable::now() : $t;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return bccomp((string) $a, (string) $b, 6) === 0;
        }

        return (string) $a === (string) $b;
    }

    private function stringify(mixed $v): ?string
    {
        return $v === null ? null : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
    }
}
