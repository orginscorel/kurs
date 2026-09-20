<?php

namespace App\Sync\Local;

use App\Support\Sensitive;
use App\Sync\BranchResolver;
use App\Sync\ChangeRecorder;
use App\Sync\RecomputeService;
use App\Sync\RowCodec;
use App\Sync\Sweeper;
use App\Sync\SyncContext;
use App\Sync\SyncFilters;
use App\Sync\SyncNumbers;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use App\Sync\SyncTable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

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
     * @return array{tables: int, rows: int, cursor: int, deferred: int, silinen: int, ms: int}
     */
    public function snapshot(?callable $progress = null): array
    {
        $t0 = hrtime(true);
        $manifest = $this->client->manifest();
        $rows = 0;
        $deferred = [];
        $received = [];   // tablo => [uuid => true]; sunucunun gönderdiği satırlar
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
                $received[$table] = [];
                do {
                    $page = $this->client->snapshotPage($table, $after, (int) config('sync.snapshot_limit', 1000));
                    DB::transaction(function () use ($page, $table, &$deferred, &$count, &$received) {
                        $this->deferForeignKeys();
                        foreach ($page['rows'] as $r) {
                            $received[$table][(string) $r['row']] = true;
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

        $purged = $this->purgeStale($manifest, $received);

        $this->sweeper->baseline(null, true);
        $this->recompute->run();
        $this->state->put('server_cursor', (int) $manifest['cursor']);
        $this->state->put('pushed_up_to', (int) (DB::table('sync_changes')->max('id') ?? 0));
        $this->state->put('snapshot_done_at', now()->toIso8601String());
        // Tam görüntü sonradan katılan tabloları da kapsadı: ayrıca çekilmesin
        $this->state->put(self::LATE_KEY, json_encode(array_values(array_map(
            fn (SyncTable $t) => self::lateKey($t), array_filter(SyncRegistry::synced(), fn (SyncTable $t) => $t->since !== null),
        ))));
        $this->afterApply(array_column($manifest['tables'], 'table'));
        // Kurulum HENÜZ ÇEVRİMİÇİYKEN numara bloğunu al: cihaz ilk günden çevrimdışı öğrenci kaydı açabilsin.
        $this->refillBlocksSafely();

        return ['tables' => count($manifest['tables']), 'rows' => $rows, 'cursor' => (int) $manifest['cursor'],
            'deferred' => count($deferred), 'silinen' => $purged, 'ms' => (int) ((hrtime(true) - $t0) / 1e6)];
    }

    /**
     * Anlık görüntüde GELMEYEN yerel satırları siler.
     *
     * Anlık görüntü yalnız ekler/günceller; sunucuda silinmiş (ya da toplu temizlenmiş) kayıtlar
     * bu adım olmadan yerelde sonsuza dek kalır ve ilk düzenlemede sunucuya geri gönderilir.
     * Dokunulmayanlar: yukarı yönlü/yerele ait tablolar ve henüz gönderilmemiş yerel değişikliği
     * olan satırlar (çevrimdışı açılan kayıt anlık görüntüde yoktur, silinmemeli).
     *
     * @param  array{tables: list<array{table: string}>}  $manifest
     * @param  array<string, array<string, true>>  $received
     */
    private function purgeStale(array $manifest, array $received): int
    {
        // "Sunucunun onayladığı" dışındaki HER yerel değişiklik satırı korunur: gönderilmemiş (status null),
        // reddedilmiş ya da imlecin üstünde kalmış kayıt anlık görüntüde bulunmasa da silinmemeli.
        $pushedUpTo = (int) ($this->state->get('pushed_up_to') ?? 0);
        $pending = [];
        $sorgu = DB::table('sync_changes')->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'rejected')->orWhere('id', '>', $pushedUpTo));
        foreach ($sorgu->get(['table_name', 'row_uuid']) as $c) {
            $pending[$c->table_name][(string) $c->row_uuid] = true;
        }

        $purged = 0;
        foreach (array_reverse($manifest['tables']) as $t) {   // bağımlı tablolar önce
            $table = $t['table'];
            $def = SyncRegistry::get($table);
            if ($def === null || ! $def->isPullable() || ! $def->hasUuid() || ! $this->schema->tableExists($table)) {
                continue;
            }
            $keep = ($received[$table] ?? []) + ($pending[$table] ?? []);
            $ids = [];
            DB::table($table)->select('id', 'uuid')->orderBy('id')->chunkById(500, function ($rows) use ($keep, &$ids) {
                foreach ($rows as $r) {
                    if ($r->uuid === null || ! isset($keep[(string) $r->uuid])) {
                        $ids[] = $r->id;
                    }
                }
            });
            if ($ids === []) {
                continue;
            }
            DB::transaction(function () use ($table, $ids, &$purged) {
                $this->deferForeignKeys();
                foreach (array_chunk($ids, 500) as $part) {
                    $purged += DB::table($table)->whereIn('id', $part)->delete();
                }
            });
        }

        return $purged;
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
            // Kurulmuş bir düğümde bu durum yalnız sunucu "yeniden anlık görüntü" dediğinde oluşur
            // (değişiklik günlüğü budandı ya da veriler sıfırlandı): kullanıcıdan kurulum istemeden
            // tam görüntüyü kendisi alır ve sunucuda artık olmayan satırları siler.
            // last_success_at: bu düğüm daha önce çalışmış demektir (işareti eski sürüm yazmamış olabilir).
            $kurulmus = $this->state->get('resnapshot_at') || $this->state->get('last_success_at');

            return $kurulmus ? $this->resnapshot($force) : ['status' => 'needs_snapshot'];
        }

        $lock = $this->acquireCycleLock($force);
        if (! $lock) {
            return ['status' => 'running'];
        }
        $this->state->writeFile(['phase' => 'syncing', 'last_attempt_at' => now()->toIso8601String()]);
        $out = ['status' => 'ok'];
        try {
            $out['sweep'] = $this->sweeper->sweep();
            // ÖNCE gönder: bekleyen değişiklikler ikincil adımların (numara bloğu, dosya) hatasına takılmasın.
            $out['push'] = $this->pushAll();
            // Reddedilenleri yeniden dene (açılış / geri çekilme süresi dolan / elle istenen / zincir): RejectedRetry
            $out['retry'] = $this->retryRejected((int) ($out['push']['accepted'] ?? 0));
            // Eşitlemeye sonradan katılan tablolar (ör. devices, app_notifications): bir kez tam çekilir (çekmeden önce:
            // yeni gelen değişiklikler bu satırlara başvurabilir)
            $out['late'] = $this->lateTables();
            $out['pull'] = $this->pullAll();
            // Numara bloğu ikincildir (yalnız yeni öğrenci numarası içindir): hatası turu düşürmez.
            $out['blocks'] = $this->refillBlocksSafely();
            // Dosyalar (satırlardan sonra: sahibi sunucuda olmalı). Dosya hatası satır eşitlemesini durdurmaz.
            try {
                $out['files'] = $this->files->run();
            } catch (SyncHttpException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Dosya eşitleme hatası', ['e' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
                $out['files'] = ['error' => $e->getMessage()];
            }
            // Açık oturum raporu + bekleyen uzaktan kapatmalar (en fazla dakikada bir; hatası turu düşürmez)
            $out['sessions'] = app(SessionReporter::class)->runSafely();
            // Biyometrik terminal durumu (web: "Son durum: … üzerinden") + masaüstünde okunan bildirimler (hatası turu düşürmez)
            $out['terminals'] = app(TerminalStatusReporter::class)->runSafely();
            $out['notification_reads'] = app(NotificationReadReporter::class)->runSafely();
            // Gönderme ve çekme bitti: tur başarılıdır. Durum yoklaması yalnız gösterge bilgisidir;
            // hatası (ör. bu sırada bağlantının kopması) başarılı turu geri almasın.
            $status = [];
            try {
                $status = $this->client->status($this->state->pendingCount());
                $out['key'] = $this->refreshKeyIfChanged($status['data_key'] ?? null);
            } catch (\Throwable $e) {
                Log::info('Eşitleme durumu alınamadı (tur başarılı sayıldı)', ['e' => $e->getMessage()]);
                $out['status_error'] = $e->getMessage();
            }
            $this->state->put('last_success_at', now()->toIso8601String());
            $this->state->put('sync_requested_at', null);
            $this->state->put('failures', 0);
            $this->state->writeFile([
                'phase' => 'idle', 'last_error' => null, 'last_error_detail' => null, 'next_attempt_at' => null,
                'open_conflicts' => $status['open_conflicts'] ?? null, 'server_cursor' => $this->state->serverCursor(),
            ]);
        } catch (SyncHttpException $e) {
            $out = ['status' => $e->isRevoked() ? 'revoked' : ($e->isOffline() ? 'offline' : 'error'), 'message' => $e->getMessage()];
            if ($e->detail !== '') {
                $out['detail'] = $e->detail;
            }
            $this->fail($e->isRevoked() ? 'revoked' : ($e->isOffline() ? 'offline' : 'error'), $e->getMessage(), $e->detail);
        } catch (\Throwable $e) {
            Log::error('Yerel eşitleme hatası', ['e' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
            $out = ['status' => 'error', 'message' => $e->getMessage()];
            $this->fail('error', $e->getMessage());
        } finally {
            $lock->release();
            Cache::forget(self::LOCK_HOLDER);
        }

        return $out;
    }

    /**
     * Sunucunun istediği yeniden anlık görüntü (kurulum ekranı açılmadan, tur içinde).
     *
     * @return array<string, mixed>
     */
    private function resnapshot(bool $force): array
    {
        $lock = $this->acquireCycleLock($force);
        if (! $lock) {
            return ['status' => 'running'];
        }
        $this->state->writeFile(['phase' => 'syncing', 'last_attempt_at' => now()->toIso8601String()]);
        try {
            $res = $this->snapshot();
            $this->state->put('resnapshot_at', null);
            $this->state->put('last_success_at', now()->toIso8601String());
            $this->state->put('failures', 0);
            $this->state->writeFile(['phase' => 'idle', 'last_error' => null, 'last_error_detail' => null, 'next_attempt_at' => null]);

            return ['status' => 'resnapshot', 'snapshot' => $res];
        } catch (SyncHttpException $e) {
            $kind = $e->isRevoked() ? 'revoked' : ($e->isOffline() ? 'offline' : 'error');
            $this->fail($kind, $e->getMessage(), $e->detail);

            return ['status' => $kind, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('Yeniden anlık görüntü hatası', ['e' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
            $this->fail('error', $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        } finally {
            $lock->release();
            Cache::forget(self::LOCK_HOLDER);
        }
    }

    /** sync_state: sonradan katılan tablolardan tam çekilmiş olanlar ("tablo@since" listesi) */
    public const LATE_KEY = 'late_tables_done';

    private const LATE_TRIED_KEY = 'late_tables_tried_at';

    public static function lateKey(SyncTable $t): string
    {
        return $t->table.'@'.$t->since;
    }

    /**
     * Eşitlemeye SONRADAN katılan tablolar (kayıt defterinde 'since'): eşleşmiş kurulum bunların mevcut satırlarını
     * değişiklik günlüğünden alamaz (satırlar tabloyu eşitlenen yapan sürümden önce yazıldı; eski sürüm bu tablonun
     * değişikliklerini atlamış da olabilir). Her tablo için BİR KEZ:
     *  1) (yukarı da giden tablolarda) yalnız bu kurulumda duran satırlar — sunucuya sorulur (sync/rows), sunucuda
     *     olmayanlar "eklendi" olarak kuyruğa yazılır ve hemen gönderilir. Sunucudan inmiş satır tekrar gönderilmez.
     *  2) sunucudaki satırlar anlık görüntü sayfalarıyla çekilir; yerelde henüz gönderilmemiş alanlar ezilmez.
     * Hata turu DÜŞÜRMEZ: sunucu tabloyu tanımıyorsa (eski sürüm), yetki yoksa ya da bağlantı koptuysa işaretlenmez,
     * 10 dakika sonra yeniden denenir.
     *
     * @return array{tables: list<string>, rows: int, orphans: int, skipped?: list<string>, waiting?: list<string>}
     */
    public function lateTables(bool $force = false): array
    {
        $done = (array) json_decode((string) $this->state->get(self::LATE_KEY, '[]'), true);
        $todo = array_filter(SyncRegistry::synced(), fn (SyncTable $t) => $t->since !== null && $t->isPullable() && ! $t->isPivot()
            && $this->schema->tableExists($t->table) && $this->schema->hasUuid($t->table) && ! in_array(self::lateKey($t), $done, true));
        $out = ['tables' => [], 'rows' => 0, 'orphans' => 0];
        if ($todo === []) {
            return $out;
        }
        $tried = (int) $this->state->get(self::LATE_TRIED_KEY, '0');
        if (! $force && $tried > 0 && time() - $tried < 600) {
            return $out + ['waiting' => array_keys($todo)];
        }
        $this->state->put(self::LATE_TRIED_KEY, (string) time());
        foreach ($todo as $table => $def) {
            try {
                $out['orphans'] += $def->isPushable() ? $this->queueLocalOnlyRows($def) : 0;
                $out['rows'] += $this->pullWholeTable($def);
            } catch (\Throwable $e) {
                Log::info('Sonradan katılan tablo eşitlenemedi (sonra yeniden denenecek)', ['table' => $table, 'e' => $e->getMessage()]);
                $out['skipped'][] = $table;

                continue;
            }
            $done[] = self::lateKey($def);
            $this->state->put(self::LATE_KEY, json_encode(array_values(array_unique($done))));
            $out['tables'][] = $table;
        }
        if (($out['skipped'] ?? []) === []) {
            $this->state->put(self::LATE_TRIED_KEY, null);
        }
        if ($out['orphans'] > 0) {
            $out['push'] = $this->pushAll();
        }
        if ($out['tables'] !== []) {
            $this->afterApply($out['tables']);
        }

        return $out;
    }

    /** Yalnız bu kurulumda duran (sunucunun tanımadığı) satırları "eklendi" olarak kuyruğa yazar. */
    private function queueLocalOnlyRows(SyncTable $def): int
    {
        $queued = 0;
        DB::table($def->table)->whereNotNull('uuid')->orderBy('id')->chunk(300, function ($rows) use ($def, &$queued) {
            $byUuid = [];
            foreach ($rows as $r) {
                $byUuid[(string) $r->uuid] = (array) $r;
            }
            $known = [];
            foreach ($this->client->rows($def->table, array_keys($byUuid))['rows'] ?? [] as $r) {
                if (($r['fields'] ?? null) !== null) {
                    $known[(string) $r['row']] = true;
                }
            }
            $recorder = app(ChangeRecorder::class);
            $branches = app(BranchResolver::class);
            foreach ($byUuid as $uuid => $row) {
                if (isset($known[$uuid]) || SyncFilters::allows($def, $row) === false) {
                    continue;
                }
                $pending = DB::table('sync_changes')->where('table_name', $def->table)->where('row_uuid', $uuid)
                    ->whereIn('op', ['insert', 'upsert'])->exists();
                if ($pending) {
                    continue;   // zaten kuyrukta / gönderilmiş / reddedilip yeniden denenecek (ör. migration'ın yazdığı)
                }
                $recorder->write($def->table, $uuid, 'insert', $this->codec->encode($def->table, $row), $branches->resolve($def, $row), ['source' => 'local']);
                $queued++;
            }
        });

        return $queued;
    }

    /** Sunucudaki tüm satırları anlık görüntü sayfalarıyla çeker (normal çekme kuralıyla uygular). */
    private function pullWholeTable(SyncTable $def): int
    {
        $table = $def->table;
        $after = 0;
        $count = 0;
        $limit = (int) config('sync.snapshot_limit', 1000);
        do {
            $page = $this->client->snapshotPage($table, $after, $limit);
            DB::transaction(function () use ($page, $table, &$count) {
                $this->deferForeignKeys();
                foreach ($page['rows'] ?? [] as $r) {
                    $change = ['table' => $table, 'row' => $r['row'], 'op' => 'upsert', 'fields' => $r['fields']];
                    if ($this->applier->apply($change) === 'deferred') {
                        DB::table('sync_deferred')->insert(['seq' => 0, 'change' => json_encode($change, JSON_UNESCAPED_UNICODE),
                            'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $count++;
                }
            });
            $after = (int) ($page['next'] ?? 0);
        } while (! empty($page['next']));

        return $count;
    }

    public const LOCK_NAME = 'kurs:sync:cycle';

    public const LOCK_HOLDER = 'kurs:sync:cycle:holder';

    /**
     * Tur kilidi. Sahibinin PID'i ayrıca yazılır: süreç öldüyse (uyku sonrası sonlandırma, uygulama kapanışı)
     * `--force` kilidi süresinin dolmasını beklemeden devralır. Canlı bir sahip varsa kilide dokunulmaz.
     */
    private function acquireCycleLock(bool $force): ?Lock
    {
        $seconds = max(30, (int) config('sync.cycle_lock_seconds', 180));
        $lock = Cache::lock(self::LOCK_NAME, $seconds);
        if (! $lock->get()) {
            if (! $force || ! self::holderIsDead()) {
                return null;
            }
            Log::warning('Eşitleme kilidi ölü süreçten devralındı', ['holder' => Cache::get(self::LOCK_HOLDER)]);
            $lock->forceRelease();
            if (! $lock->get()) {
                return null;
            }
        }
        Cache::put(self::LOCK_HOLDER, ['pid' => getmypid(), 'at' => time()], $seconds);

        return $lock;
    }

    /** Kilit sahibi süreç artık yok mu? (PID bilinmiyorsa ya da denetlenemiyorsa: hayır) */
    public static function holderIsDead(): bool
    {
        $holder = Cache::get(self::LOCK_HOLDER);
        $pid = is_array($holder) ? (int) ($holder['pid'] ?? 0) : 0;
        if ($pid <= 0) {
            return true; // sahip kaydı yok → eski sürümden ya da yarıda kalmış süreçten kalmış kilit
        }
        if ($pid === getmypid()) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return ! @posix_kill($pid, 0) && (! function_exists('posix_get_last_error') || posix_get_last_error() === 3); // ESRCH
        }
        if (is_dir('/proc')) {
            return ! is_dir('/proc/'.$pid);
        }

        return false;
    }

    /** Açılışta / uyanmada: canlı sahibi olmayan tur kilidini kaldırır. */
    public function releaseStaleLock(): bool
    {
        if (! self::holderIsDead()) {
            return false;
        }
        Cache::lock(self::LOCK_NAME)->forceRelease();
        Cache::forget(self::LOCK_HOLDER);

        return true;
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
        $this->state->put('data_key_fp', Sensitive::dataKeyFingerprint());
    }

    private function fail(string $phase, string $message, string $detail = ''): void
    {
        $failures = (int) $this->state->get('failures', '0') + 1;
        $this->state->put('failures', $failures);
        // Çevrimdışıyken beklemeyi büyütmeyiz: bağlantı geri geldiğinde kuyruk dakikalarca beklemesin.
        // Üstel geri çekilme yalnız sunucunun yanıt VERDİĞİ hatalar içindir (yükü artırmayalım).
        $delay = $phase === 'offline'
            ? max(5, (int) config('sync.offline_retry_seconds', 20))
            : min((int) config('sync.max_backoff_seconds', 600), (int) (15 * (2 ** min(10, $failures - 1))));
        $this->state->put('sync_requested_at', null);
        $this->state->writeFile([
            'phase' => $phase, 'last_error' => mb_substr($message, 0, 300), 'last_error_detail' => $detail !== '' ? mb_substr($detail, 0, 300) : null,
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
            $res = $this->sendChanges($pending, fn () => $this->state->put('pushed_up_to', (int) $rows->last()->id));

            $total['batches']++;
            $total['sent'] += $pending->count();
            foreach (['accepted', 'rejected', 'conflicts', 'duplicates'] as $k) {
                $total[$k] += (int) ($res[$k] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Değişiklikleri gönderir ve sonuçları aynı transaction'da uygular.
     *
     * @param  Collection<int, object>  $pending
     * @return array<string, mixed> sunucu yanıtı
     */
    private function sendChanges(Collection $pending, ?callable $after = null, bool $retry = false): array
    {
        $changes = $pending->map(fn ($r) => $this->wire($r))->values()->all();
        $res = $changes === [] ? ['results' => []] : $this->client->push([
            'base_cursor' => $this->state->serverCursor(),
            'device_time' => now()->toIso8601String(),
            'changes' => $changes,
        ]);
        $byId = collect($res['results'] ?? [])->keyBy('id');

        DB::transaction(function () use ($pending, $byId, $after, $retry) {
            $this->deferForeignKeys();
            foreach ($pending as $r) {
                $result = $byId[$r->change_uuid] ?? null;
                if (! $result) {
                    continue;
                }
                $this->handleResult($r, $result, $retry);
            }
            if ($after) {
                $after();
            }
        });

        return $res;
    }

    /**
     * Reddedilen değişiklikleri yeniden dene (kurallar: RejectedRetry). Zincir: bir geçişte kabul olursa
     * 'missing_reference' ile reddedilenler aynı turda tekrar gönderilir; ilerleme yoksa durur.
     *
     * @return array{sent: int, accepted: int, rejected: int, passes: int}
     */
    public function retryRejected(int $acceptedThisCycle = 0): array
    {
        $plan = app(RejectedRetry::class);
        $triggers = $plan->takeTriggers();
        $out = ['sent' => 0, 'accepted' => 0, 'rejected' => 0, 'passes' => 0];
        $progress = $acceptedThisCycle > 0;
        $tried = [];
        for ($pass = 0; $pass < RejectedRetry::MAX_PASSES; $pass++) {
            $rows = $plan->due($pass === 0 ? $triggers['manual'] : null, $pass === 0 && $triggers['startup'], $progress, $tried);
            if ($rows->isEmpty()) {
                break;
            }
            try {
                $this->sendChanges($rows, null, true);
            } catch (SyncHttpException $e) {
                if ($pass === 0 && $triggers['manual'] !== null) {
                    // elle istenen deneme bağlantı hatasında kaybolmasın
                    $plan->requestManual($triggers['manual'] === '*' ? null : $triggers['manual']);
                }
                throw $e;
            }
            $after = DB::table('sync_changes')->whereIn('id', $rows->pluck('id'))->pluck('status', 'id');
            $accepted = $after->filter(fn ($st) => $st !== 'rejected')->count();
            foreach ($rows as $r) {
                $tried[(int) $r->id] = true;
            }
            $out['passes']++;
            $out['sent'] += $rows->count();
            $out['accepted'] += $accepted;
            $out['rejected'] += $rows->count() - $accepted;
            $progress = $accepted > 0;
            if (! $progress) {
                break;
            }
        }

        return $out;
    }

    private function wire(object $r): array
    {
        $fields = $r->fields === null ? null : json_decode($r->fields, true);
        $at = CarbonImmutable::parse($r->created_at)->toIso8601String();
        if ($r->op === 'command') {
            return ['id' => $r->change_uuid, 'command' => $fields['name'], 'args' => $fields['args'] ?? [],
                'uuids' => $fields['uuids'] ?? [], 'numbers' => $fields['numbers'] ?? [], 'at' => $at];
        }

        return ['id' => $r->change_uuid, 'table' => $r->table_name, 'op' => $r->op, 'row' => $r->row_uuid, 'fields' => $fields, 'at' => $at];
    }

    private function handleResult(object $r, array $result, bool $retry = false): void
    {
        $status = $result['status'] ?? 'rejected';
        if ($status === 'duplicate') {
            $status = $result['previous'] ?? 'accepted';
        }
        $fields = $r->fields === null ? [] : (array) json_decode($r->fields, true);

        if ($status === 'rejected') {
            DB::table('sync_changes')->where('id', $r->id)->update(['status' => 'rejected', 'error' => mb_substr((string) ($result['message'] ?? ''), 0, 300)]);
            app(RejectedRetry::class)->noteRejected((int) $r->id, isset($result['code']) ? (string) $result['code'] : null, $retry);
            if ($r->op === 'command') {
                $this->rollbackCommand($fields);
            } elseif ($r->op === 'insert') {
                // Sunucu kabul etmedi: yerel satır kalır, kullanıcı "reddedilenler" listesinde görür
            }

            return;
        }
        DB::table('sync_changes')->where('id', $r->id)->update(['status' => 'pushed', 'error' => null]);
        if ($retry) {
            app(RejectedRetry::class)->forget([(int) $r->id]);
            if ($r->op === 'command') {
                // Ret anında yerelde geri alınan komut artık sunucuda yürüdü: oluşan/değişen satırları sunucudan al
                // (kaynak cihaza yankı gönderilmediği için çekmeyle gelmezler).
                $this->restoreCommandRows($fields);
            }
        }

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

    /** Yeniden denemede kabul edilen komutun satırları (oluşturduğu + dokunduğu) sunucudan alınır. */
    private function restoreCommandRows(array $fields): void
    {
        $byTable = [];
        foreach (['uuids', 'touched'] as $k) {
            foreach ((array) ($fields[$k] ?? []) as $table => $uuids) {
                $byTable[$table] = array_values(array_unique(array_merge($byTable[$table] ?? [], (array) $uuids)));
            }
        }
        foreach ($byTable as $table => $uuids) {
            try {
                $res = $this->client->rows((string) $table, $uuids);
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
                $this->state->put('resnapshot_at', now()->toIso8601String());   // sonraki tur kendiliğinden tam görüntü alır
                throw new SyncHttpException('Sunucu yeniden anlık görüntü istedi; tam görüntü bir sonraki turda alınacak.', 409, 'resnapshot');
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

    /**
     * Yeni numara bloğu (azaldıysa) — hatası turu düşürmez.
     * Cihaz erişimi iptal edildiyse (revoked) yine de yukarı taşınır: o durumun ayrı bir aşaması var.
     *
     * @return array<string, string>
     */
    private function refillBlocksSafely(): array
    {
        try {
            return $this->refillBlocks();
        } catch (SyncHttpException $e) {
            if ($e->isRevoked()) {
                throw $e;
            }
            Log::info('Numara bloğu alınamadı (tur sürüyor)', ['e' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::warning('Numara bloğu hatası', ['e' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);

            return ['error' => $e->getMessage()];
        }
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
            app(PermissionRegistrar::class)->forgetCachedPermissions();
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
