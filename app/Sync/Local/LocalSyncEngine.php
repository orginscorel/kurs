<?php

namespace App\Sync\Local;

use App\Sync\RecomputeService;
use App\Sync\RowCodec;
use App\Sync\Sweeper;
use App\Sync\SyncContext;
use App\Sync\SyncNumbers;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Yerel düğüm eşitleme döngüsü: süpür → numara bloğu → GÖNDER → ÇEK → yeniden hesapla.
 * Hata olursa üstel geri çekilme (durum dosyasında next_attempt_at).
 */
class LocalSyncEngine
{
    public function __construct(
        private readonly SyncClient $client,
        private readonly LocalState $state,
        private readonly LocalApplier $applier,
        private readonly Sweeper $sweeper,
        private readonly RowCodec $codec,
        private readonly SyncContext $context,
        private readonly SyncSchema $schema,
        private readonly RecomputeService $recompute,
        private readonly LocalFileSync $files,
    ) {}

    /**
     * Eşleştir: jeton + cihaz kodu + (varsa) kurum veri anahtarı. Anahtar .env'e değil sync_state'e
     * (yerel APP_KEY ile şifreli) yazılır; masaüstü paketinde işletim sistemi anahtar zincirine taşınır.
     *
     * @return array<string, mixed>
     */
    public function pair(string $serverUrl, string $code, string $login, string $password, string $deviceName, string $platform): array
    {
        $keypair = sodium_crypto_box_keypair();
        $res = SyncClient::pair($serverUrl, [
            'code' => $code, 'login' => $login, 'password' => $password, 'device_name' => $deviceName,
            'platform' => $platform, 'mode' => 'desktop', 'app_version' => (string) config('app.version', 'yerel-1'),
            'public_key' => base64_encode(sodium_crypto_box_publickey($keypair)),
        ]);

        $this->state->put('server_url', rtrim($serverUrl, '/'));
        $this->state->put('device_token', Crypt::encryptString((string) $res['token']));
        $this->state->put('device_token_set', '1');
        $this->state->put('device_uuid', $res['device']['uuid'] ?? null);
        $this->state->put('device_code', $res['device']['code'] ?? null);
        $this->state->put('branch_id_server', $res['branch']['id'] ?? null);
        $this->state->put('device_secret', Crypt::encryptString(base64_encode(sodium_crypto_box_secretkey($keypair))));

        $keyInfo = 'yok';
        if (! empty($res['key_bundle']['sealed'])) {
            $opened = sodium_crypto_box_seal_open(base64_decode($res['key_bundle']['sealed']), $keypair);
            if ($opened !== false) {
                $bundle = json_decode($opened, true);
                $this->storeDataKey((string) $bundle['key']);
                $keyInfo = 'alındı ('.($bundle['kind'] ?? '?').')';
            }
        } else {
            $keyInfo = (string) ($res['key_bundle']['reason'] ?? 'yok');
        }
        $this->state->writeFile(['phase' => 'idle', 'last_error' => null]);

        return ['device' => $res['device'], 'key' => $keyInfo];
    }

    /**
     * İlk kurulum anlık görüntüsü.
     *
     * @return array{tables: int, rows: int, cursor: int, deferred: int, ms: int}
     */
    public function snapshot(?callable $progress = null): array
    {
        $t0 = hrtime(true);
        $manifest = $this->client->manifest();
        $rows = 0;
        $deferred = [];
        $this->codec->forget();
        $this->applier->snapshotMode = true;
        $this->applier->selfRefs = [];

        try {
            foreach ($manifest['tables'] as $t) {
                $table = $t['table'];
                if (! $this->schema->tableExists($table)) {
                    continue;
                }
                $after = 0;
                $count = 0;
                do {
                    $page = $this->client->snapshotPage($table, $after, (int) config('sync.snapshot_limit', 1000));
                    DB::transaction(function () use ($page, $table, &$deferred, &$count) {
                        $this->deferForeignKeys();
                        foreach ($page['rows'] as $r) {
                            $res = $this->applier->apply(['table' => $table, 'row' => $r['row'], 'op' => 'upsert', 'fields' => $r['fields']]);
                            if ($res === 'deferred') {
                                $deferred[] = ['table' => $table, 'row' => $r['row'], 'op' => 'upsert', 'fields' => $r['fields']];
                            }
                            $count++;
                        }
                    });
                    $after = (int) ($page['next'] ?? 0);
                } while (! empty($page['next']));
                $rows += $count;
                if ($progress) {
                    $progress($table, $count);
                }
            }

            // Döngüsel referanslar: ikinci tur
            DB::transaction(function () use (&$deferred) {
                $this->deferForeignKeys();
                for ($pass = 0; $pass < 3 && $deferred !== []; $pass++) {
                    $left = [];
                    foreach ($deferred as $c) {
                        if ($this->applier->apply($c) === 'deferred') {
                            $left[] = $c;
                        }
                    }
                    $deferred = $left;
                }
                $this->applier->fixSelfRefs();
            });
        } finally {
            $this->applier->snapshotMode = false;
        }

        foreach ($deferred as $c) {
            DB::table('sync_deferred')->insert(['seq' => 0, 'change' => json_encode($c), 'attempts' => 1,
                'last_error' => 'bağlı kayıt yok', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->sweeper->baseline(null, true);
        $this->recompute->run();
        $this->state->put('server_cursor', (int) $manifest['cursor']);
        $this->state->put('pushed_up_to', (int) (DB::table('sync_changes')->max('id') ?? 0));
        $this->state->put('snapshot_done_at', now()->toIso8601String());
        $this->afterApply(array_column($manifest['tables'], 'table'));

        return ['tables' => count($manifest['tables']), 'rows' => $rows, 'cursor' => (int) $manifest['cursor'],
            'deferred' => count($deferred), 'ms' => (int) ((hrtime(true) - $t0) / 1e6)];
    }

    /**
     * Tek eşitleme turu.
     *
     * @return array<string, mixed>
     */
    public function cycle(bool $force = false): array
    {
        if (! $this->client->isPaired()) {
            $this->state->writeFile(['phase' => 'unpaired']);

            return ['status' => 'unpaired'];
        }
        $file = $this->state->file();
        if (! $force && ! empty($file['next_attempt_at']) && strtotime($file['next_attempt_at']) > time()
            && ! $this->state->get('sync_requested_at')) {
            return ['status' => 'backoff', 'next_attempt_at' => $file['next_attempt_at']];
        }
        if (! $this->state->get('snapshot_done_at')) {
            return ['status' => 'needs_snapshot'];
        }

        $lock = Cache::lock('kurs:sync:cycle', 600);
        if (! $lock->get()) {
            return ['status' => 'running'];
        }
        $this->state->writeFile(['phase' => 'syncing']);
        $out = ['status' => 'ok'];
        try {
            $out['sweep'] = $this->sweeper->sweep();
            $out['blocks'] = $this->refillBlocks();
            $out['push'] = $this->pushAll();
            $out['pull'] = $this->pullAll();
            // Dosyalar (satırlardan sonra: sahibi sunucuda olmalı). Dosya hatası satır eşitlemesini durdurmaz.
            try {
                $out['files'] = $this->files->run();
            } catch (SyncHttpException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Dosya eşitleme hatası', ['e' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
                $out['files'] = ['error' => $e->getMessage()];
            }
            $status = $this->client->status($this->state->pendingCount());
            $out['key'] = $this->refreshKeyIfChanged($status['data_key'] ?? null);
            $this->state->put('last_success_at', now()->toIso8601String());
            $this->state->put('sync_requested_at', null);
            $this->state->put('failures', 0);
            $this->state->writeFile([
                'phase' => 'idle', 'last_error' => null, 'next_attempt_at' => null,
                'open_conflicts' => $status['open_conflicts'] ?? null, 'server_cursor' => $this->state->serverCursor(),
            ]);
        } catch (SyncHttpException $e) {
            $out = ['status' => $e->isRevoked() ? 'revoked' : ($e->isOffline() ? 'offline' : 'error'), 'message' => $e->getMessage()];
            $this->fail($e->isRevoked() ? 'revoked' : ($e->isOffline() ? 'offline' : 'error'), $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Yerel eşitleme hatası', ['e' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
            $out = ['status' => 'error', 'message' => $e->getMessage()];
            $this->fail('error', $e->getMessage());
        } finally {
            $lock->release();
        }

        return $out;
    }

    /** Sunucudaki kurum veri anahtarı değiştiyse (ya da sonradan tanımlandıysa) mühürlü paketle yenile. */
    public function refreshKeyIfChanged(?string $serverFingerprint): ?string
    {
        if (! $serverFingerprint || $serverFingerprint === $this->state->get('data_key_fp')) {
            return null;
        }
        $secret = $this->state->get('device_secret');
        if (! $secret) {
            return 'cihaz anahtarı yok';
        }
        $res = $this->client->keyBundle();
        $sealed = $res['key_bundle']['sealed'] ?? null;
        if (! $sealed) {
            return (string) ($res['key_bundle']['reason'] ?? 'paket yok');
        }
        $sk = base64_decode(Crypt::decryptString($secret));
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($sk, sodium_crypto_box_publickey_from_secretkey($sk));
        $opened = sodium_crypto_box_seal_open(base64_decode($sealed), $keypair);
        if ($opened === false) {
            return 'paket açılamadı';
        }
        $this->storeDataKey((string) json_decode($opened, true)['key']);

        return 'yenilendi';
    }

    private function storeDataKey(string $key): void
    {
        $this->state->put('data_key', Crypt::encryptString($key));
        config(['kurs.data_key' => $key]);
        $this->state->put('data_key_fp', \App\Support\Sensitive::dataKeyFingerprint());
    }

    private function fail(string $phase, string $message): void
    {
        $failures = (int) $this->state->get('failures', '0') + 1;
        $this->state->put('failures', $failures);
        $delay = min((int) config('sync.max_backoff_seconds', 600), (int) (15 * (2 ** min(10, $failures - 1))));
        $this->state->put('sync_requested_at', null);
        $this->state->writeFile([
            'phase' => $phase, 'last_error' => mb_substr($message, 0, 300),
            'next_attempt_at' => now()->addSeconds($delay)->toIso8601String(), 'failures' => $failures,
        ]);
    }

    /** @return array{sent: int, accepted: int, rejected: int, conflicts: int, duplicates: int, batches: int} */
    public function pushAll(): array
    {
        $total = ['sent' => 0, 'accepted' => 0, 'rejected' => 0, 'conflicts' => 0, 'duplicates' => 0, 'batches' => 0];
        $limit = (int) config('sync.push_limit', 1000);
        for ($guard = 0; $guard < 1000; $guard++) {
            $from = $this->state->pushedUpTo();
            $rows = DB::table('sync_changes')->where('id', '>', $from)->orderBy('id')->limit($limit)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $pending = $rows->whereNull('status');
            $payload = [
                'base_cursor' => $this->state->serverCursor(),
                'device_time' => now()->toIso8601String(),
                'changes' => $pending->map(fn ($r) => $this->wire($r))->values()->all(),
            ];
            $res = $payload['changes'] === [] ? ['results' => []] : $this->client->push($payload);
            $byId = collect($res['results'] ?? [])->keyBy('id');

            DB::transaction(function () use ($pending, $byId, $rows) {
                $this->deferForeignKeys();
                foreach ($pending as $r) {
                    $result = $byId[$r->change_uuid] ?? null;
                    if (! $result) {
                        continue;
                    }
                    $this->handleResult($r, $result);
                }
                $this->state->put('pushed_up_to', (int) $rows->last()->id);
            });

            $total['batches']++;
            $total['sent'] += count($payload['changes']);
            foreach (['accepted', 'rejected', 'conflicts', 'duplicates'] as $k) {
                $total[$k] += (int) ($res[$k] ?? 0);
            }
        }

        return $total;
    }

    private function wire(object $r): array
    {
        $fields = $r->fields === null ? null : json_decode($r->fields, true);
        $at = \Carbon\CarbonImmutable::parse($r->created_at)->toIso8601String();
        if ($r->op === 'command') {
            return ['id' => $r->change_uuid, 'command' => $fields['name'], 'args' => $fields['args'] ?? [],
                'uuids' => $fields['uuids'] ?? [], 'numbers' => $fields['numbers'] ?? [], 'at' => $at];
        }

        return ['id' => $r->change_uuid, 'table' => $r->table_name, 'op' => $r->op, 'row' => $r->row_uuid, 'fields' => $fields, 'at' => $at];
    }

    private function handleResult(object $r, array $result): void
    {
        $status = $result['status'] ?? 'rejected';
        if ($status === 'duplicate') {
            $status = $result['previous'] ?? 'accepted';
        }
        $fields = $r->fields === null ? [] : (array) json_decode($r->fields, true);

        if ($status === 'rejected') {
            DB::table('sync_changes')->where('id', $r->id)->update(['status' => 'rejected', 'error' => mb_substr((string) ($result['message'] ?? ''), 0, 300)]);
            if ($r->op === 'command') {
                $this->rollbackCommand($fields);
            } elseif ($r->op === 'insert') {
                // Sunucu kabul etmedi: yerel satır kalır, kullanıcı "reddedilenler" listesinde görür
            }

            return;
        }
        DB::table('sync_changes')->where('id', $r->id)->update(['status' => 'pushed']);

        if (! empty($result['unused_uuids']) && is_array($result['unused_uuids'])) {
            $this->deleteLocal($result['unused_uuids']);
        }
    }

    /** Reddedilen komut: yerelde oluşan geçici satırlar silinir, değişen satırlar sunucudan geri yüklenir. */
    private function rollbackCommand(array $fields): void
    {
        $this->deleteLocal((array) ($fields['uuids'] ?? []));
        foreach ((array) ($fields['touched'] ?? []) as $table => $uuids) {
            try {
                $res = $this->client->rows((string) $table, (array) $uuids);
            } catch (SyncHttpException) {
                continue;
            }
            foreach ($res['rows'] ?? [] as $row) {
                if ($row['fields'] !== null) {
                    $this->applier->apply(['table' => $table, 'row' => $row['row'], 'op' => 'upsert', 'fields' => $row['fields']]);
                }
            }
        }
        $this->recompute->run();
    }

    /** @param array<string, list<string>> $byTable */
    private function deleteLocal(array $byTable): void
    {
        $this->context->applying(function () use ($byTable) {
            // Çocuk tablolar önce (kayıt defteri sırasının tersi)
            foreach (array_reverse(array_keys(SyncRegistry::all())) as $table) {
                if (empty($byTable[$table]) || ! $this->schema->hasUuid($table)) {
                    continue;
                }
                $def = SyncRegistry::get($table);
                foreach (DB::table($table)->whereIn('uuid', (array) $byTable[$table])->pluck('id') as $id) {
                    DB::table($table)->where('id', $id)->delete();
                    $this->sweeper->forget($def, (string) $id);
                }
            }
        });
    }

    /** @return array{received: int, applied: int, deferred: int, pages: int, cursor: int} */
    public function pullAll(): array
    {
        $out = ['received' => 0, 'applied' => 0, 'deferred' => 0, 'pages' => 0, 'cursor' => $this->state->serverCursor()];
        $this->applier->touched = [];
        $this->applier->needRows = [];
        $this->codec->forget();

        for ($guard = 0; $guard < 10000; $guard++) {
            $cursor = $this->state->serverCursor();
            $res = $this->client->pull($cursor, (int) config('sync.pull_limit', 500));
            if (! empty($res['resnapshot'])) {
                $this->state->put('snapshot_done_at', null);
                throw new SyncHttpException('Sunucu değişiklik günlüğü budanmış; yeniden anlık görüntü gerekiyor (php artisan kurs:sync --snapshot).', 409, 'resnapshot');
            }
            DB::transaction(function () use ($res, &$out) {
                $this->deferForeignKeys();
                foreach ($res['changes'] as $c) {
                    $out['received']++;
                    $r = $this->applier->apply($c);
                    if ($r === 'deferred') {
                        $out['deferred']++;
                        DB::table('sync_deferred')->insert(['seq' => (int) $c['seq'], 'change' => json_encode($c, JSON_UNESCAPED_UNICODE),
                            'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    } elseif ($r === 'applied') {
                        $out['applied']++;
                    }
                }
                $this->state->put('server_cursor', (int) $res['cursor']);
            });
            $out['pages']++;
            $out['cursor'] = (int) $res['cursor'];
            if (empty($res['more']) || ((int) $res['cursor'] === $cursor && $res['changes'] === [])) {
                break;
            }
        }

        $this->fetchNeededRows();
        $out['deferred_left'] = $this->retryDeferred();
        $this->afterApply(array_keys($this->applier->touched));

        return $out;
    }

    private function fetchNeededRows(): void
    {
        foreach ($this->applier->needRows as $table => $uuids) {
            $res = $this->client->rows($table, array_keys($uuids));
            DB::transaction(function () use ($res, $table) {
                $this->deferForeignKeys();
                foreach ($res['rows'] ?? [] as $row) {
                    if ($row['fields'] !== null) {
                        $this->applier->apply(['table' => $table, 'row' => $row['row'], 'op' => 'upsert', 'fields' => $row['fields']]);
                    }
                }
            });
        }
        $this->applier->needRows = [];
    }

    private function retryDeferred(): int
    {
        $left = 0;
        foreach (DB::table('sync_deferred')->orderBy('seq')->orderBy('id')->get() as $d) {
            $c = json_decode($d->change, true);
            $r = DB::transaction(function () use ($c) {
                $this->deferForeignKeys();

                return $this->applier->apply($c);
            });
            if ($r === 'deferred' && $d->attempts < 20) {
                DB::table('sync_deferred')->where('id', $d->id)->update(['attempts' => $d->attempts + 1, 'updated_at' => now()]);
                $left++;
            } else {
                DB::table('sync_deferred')->where('id', $d->id)->delete();
            }
        }

        return $left;
    }

    /** Yeni numara bloğu (azaldıysa). */
    private function refillBlocks(): array
    {
        $out = [];
        foreach (SyncNumbers::LEASABLE as $name) {
            if (SyncNumbers::remaining($name) < (int) config('sync.number_block_refill_below', 10)) {
                $res = $this->client->numberBlock($name, (int) config('sync.number_block_size', 50));
                $b = $res['block'];
                DB::table('sync_number_blocks')->insert([
                    'branch_id' => null, 'device_id' => null, 'name' => $b['name'], 'start' => $b['start'], 'end' => $b['end'],
                    'next' => $b['start'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                $out[$name] = $b['start'].'-'.$b['end'];
            }
        }

        return $out;
    }

    /** @param list<string> $tables */
    private function afterApply(array $tables): void
    {
        if ($targets = RecomputeService::targetsFor($tables)) {
            $this->recompute->run($targets);
        }
        if (array_intersect($tables, ['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions'])) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
        if (in_array('settings', $tables, true)) {
            foreach (DB::table('settings')->select('branch_id', 'group')->distinct()->get() as $s) {
                Cache::forget("settings:{$s->branch_id}:{$s->group}");
            }
        }
        $this->schema->flush();
    }

    /** SQLite: transaction içinde yabancı anahtar denetimi commit'e ertelenir (sıra bağımsız). */
    private function deferForeignKeys(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
    }
}
