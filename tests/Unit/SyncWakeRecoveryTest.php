<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\User;
use App\Services\Crm\LeadService;
use App\Support\BranchContext;
use App\Support\Permissions;
use App\Sync\ChangeRecorder;
use App\Sync\Local\LocalState;
use App\Sync\Local\LocalSyncEngine;
use App\Sync\Sweeper;
use App\Sync\SyncSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Console\Commands\Sync\SyncRun;
use App\Sync\Local\SyncClient;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Uyku / kapanış sonrası eşitlemenin kendini toparlaması (1.12.1):
 *  - ölü süreçten kalan tur kilidi --force / --reset-locks ile devralınır, canlı sahibinkine dokunulmaz,
 *  - bağlantı hatasının teknik nedeni durum dosyasında saklanır,
 *  - son deneme zamanı yazılır; bayat "çevrimdışı" gösterilmez,
 *  - --loop saat sıçramasında uzamaz, masaüstünde eşitleme zamanlayıcı muteksine bağlı değildir.
 */
class SyncWakeRecoveryTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.sqlite.database') !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config([
            'kurs.silent_events' => true,
            'kurs.node' => 'server',
            'kurs.data_key' => null,
            'sync.sweep_before_pull_seconds' => 0,
            'sync.files_index_seconds' => 0,
        ]);
        Event::fake([\App\Events\StudentCreated::class, \App\Events\LeadConverted::class]);
        Storage::fake('local');

        $this->artisan('migrate', ['--force' => true])->run();
        app(SyncSchema::class)->flush();
        app(ChangeRecorder::class)->reset();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);
        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Müdür', 'username' => 'mudur1',
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true,
        ]);
        $this->admin->assignRole('yonetici');
        Auth::setUser($this->admin);

        app(SyncSchema::class)->backfillUuids();
        app(SyncSchema::class)->flush();
        app(Sweeper::class)->baseline();

        // Yerel düğüm: eşleşmiş ve ilk eşitlemesi bitmiş bir masaüstü kurulumu gibi davran
        config([
            'kurs.node' => 'local',
            'sync.device_code' => 'D7',
            'sync.server_url' => 'https://ornek-sunucu.test',
            'sync.device_token' => 'test-cihaz-jetonu',
            'sync.offline_retry_seconds' => 20,
        ]);
        app(\App\Sync\RowCodec::class)->forget();
        app(LocalState::class)->put('snapshot_done_at', now()->toIso8601String());
        app(LocalState::class)->put('device_code', 'D7');
    }

    protected function tearDown(): void
    {
        config(['kurs.node' => 'server', 'kurs.data_key' => null, 'sync.server_url' => null, 'sync.device_token' => null]);
        parent::tearDown();
    }

    /** Sunucuya ulaşılamıyor: her dış istek bağlantı hatası verir. */
    private function fakeOffline(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
    }

    /** Önceki sahte yanıtları tamamen unut (çevrimdışı → çevrimiçi geçişi için). */
    private function resetHttp(): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
    }

    /** Sunucu ayakta: eşitleme uçlarının en küçük geçerli yanıtları. */
    private function fakeOnline(array $pushResults = []): void
    {
        $this->resetHttp();
        Http::fake([
            '*/sync/push' => Http::response(['results' => $pushResults, 'accepted' => count($pushResults)]),
            '*/sync/pull*' => Http::response(['changes' => [], 'cursor' => 0, 'more' => false]),
            '*/sync/status*' => Http::response(['open_conflicts' => 0, 'data_key' => null]),
            '*/sync/files/manifest*' => Http::response(['files' => [], 'next' => null, 'more' => false]),
            // Numara bloğu ucu KASTEN hata veriyor: ikincil adım turu düşürmemeli
            '*/sync/number-blocks' => Http::response(['message' => 'Bu sayaç için blok ayrılamaz.'], 422),
        ]);
    }


    private function holdLockAs(int $pid): void
    {
        $this->assertTrue(Cache::lock(LocalSyncEngine::LOCK_NAME, 180)->get());
        Cache::put(LocalSyncEngine::LOCK_HOLDER, ['pid' => $pid, 'at' => time()], 180);
    }

    /** Uykuda/kapanışta ölen sürecin kilidi: --force (Şimdi eşitle, uyanma) kilidi HEMEN devralır. */
    public function test_olu_surecin_tur_kilidi_force_ile_devralinir(): void
    {
        $this->fakeOnline();
        $this->holdLockAs(2147480000); // böyle bir süreç yok

        $this->assertSame('running', app(LocalSyncEngine::class)->cycle(false)['status'], 'Zorlanmamış tur kilide saygı duymalı.');
        $this->assertSame('ok', app(LocalSyncEngine::class)->cycle(true)['status'], 'Ölü sahibin kilidi --force ile devralınmalı.');
        $this->assertNull(Cache::get(LocalSyncEngine::LOCK_HOLDER), 'Tur bitince sahip kaydı silinmeli.');
        $this->assertTrue(Cache::lock(LocalSyncEngine::LOCK_NAME, 10)->get(), 'Tur bitince kilit bırakılmalı.');
    }

    /** Canlı bir tur sürüyorsa --force bile ona dokunmaz ("Eşitleme zaten sürüyor"). */
    public function test_canli_sahibin_kilidi_devralinmaz(): void
    {
        $this->fakeOnline();
        $this->holdLockAs(getmypid());

        $this->assertSame('running', app(LocalSyncEngine::class)->cycle(true)['status']);
    }

    public function test_reset_locks_olu_kilidi_ve_zamanlayici_mutekslerini_temizler(): void
    {
        $this->holdLockAs(2147480000);
        $this->artisan('kurs:sync', ['--reset-locks' => true, '--json' => true])->assertSuccessful();
        $this->assertTrue(Cache::lock(LocalSyncEngine::LOCK_NAME, 10)->get(), 'Ölü sahibin kilidi açılışta kalkmalı.');

        Cache::lock(LocalSyncEngine::LOCK_NAME)->forceRelease();
        $this->holdLockAs(getmypid());
        $this->artisan('kurs:sync', ['--reset-locks' => true])->assertSuccessful();
        $this->assertFalse(Cache::lock(LocalSyncEngine::LOCK_NAME, 10)->get(), 'Canlı sahibin kilidine dokunulmamalı.');
    }

    /** Bağlantı hatasının GERÇEK nedeni kaybolmaz: durum dosyasında ayrı "ayrıntı" alanı, kullanıcı metni sade. */
    public function test_baglanti_hatasinin_teknik_ayrintisi_saklanir(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: ornek-sunucu.test (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://ornek-sunucu.test/api/v1/sync/pull?cursor=0'));
        $out = app(LocalSyncEngine::class)->cycle(true);

        $this->assertSame('offline', $out['status']);
        $this->assertSame('cURL error 6: Could not resolve host: ornek-sunucu.test', $out['detail']);
        $file = app(LocalState::class)->file();
        $this->assertStringStartsWith('Sunucuya ulaşılamıyor.', $file['last_error']);
        $this->assertStringNotContainsString('cURL', $file['last_error']);
        $this->assertSame('cURL error 6: Could not resolve host: ornek-sunucu.test', $file['last_error_detail']);
        $this->assertSame($file['last_error_detail'], app(LocalState::class)->summary()['last_error_detail']);

        // Başarılı tur ayrıntıyı temizler
        $this->fakeOnline();
        $this->assertSame('ok', app(LocalSyncEngine::class)->cycle(true)['status']);
        $this->assertNull(app(LocalState::class)->file()['last_error_detail']);
    }

    public function test_kisa_ayrinti_bicimi(): void
    {
        $this->assertSame('cURL error 28: Resolving timed out after 10000 milliseconds',
            SyncClient::shortDetail('cURL error 28: Resolving timed out after 10000 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://x.test/api/v1/sync/pull'));
    }

    /** Her tur başında last_attempt_at; son deneme 5 dk'dan eskiyse gösterge bayat "çevrimdışı"da kalmaz. */
    public function test_son_deneme_zamani_yazilir_ve_bayat_cevrimdisi_gosterilmez(): void
    {
        $this->fakeOffline();
        app(LocalSyncEngine::class)->cycle(true);
        $file = app(LocalState::class)->file();
        $this->assertNotEmpty($file['last_attempt_at']);
        $this->assertLessThanOrEqual(5, abs(time() - strtotime($file['last_attempt_at'])));
        $this->assertSame('offline', app(LocalState::class)->summary()['phase']);

        // 10 dakika sonra (tur hiç çalışmamış): "stale" → ön yüzde "Eşitleme bekliyor — Şimdi eşitle"
        $this->travel(10)->minutes();
        $this->assertSame('stale', app(LocalState::class)->summary()['phase']);
    }

    /** --loop: saat sıçraması (uyku/askıya alma) ya da geri alınması döngüyü uzatmaz. */
    public function test_dongu_saat_sicramasinda_biter(): void
    {
        $cmd = app(SyncRun::class);
        $w0 = 1_000_000;
        $m0 = 5_000_000_000;
        $this->assertFalse($cmd->loopExpired(55, $w0, $m0, $w0 + 10, $m0 + 10e9), 'Normal akış: 10 sn sonra sürer.');
        $this->assertTrue($cmd->loopExpired(55, $w0, $m0, $w0 + 56, $m0 + 56e9), 'Süre dolunca biter.');
        $this->assertTrue($cmd->loopExpired(55, $w0, $m0, $w0 + 16 * 3600, $m0 + 12e9), 'Uykudan dönünce (duvar saati sıçradı) hemen biter.');
        $this->assertTrue($cmd->loopExpired(55, $w0, $m0, $w0 - 3600, $m0 + 12e9), 'Saat geri alınırsa döngü uzamaz.');
    }

    /** Masaüstü paketinde eşitleme Laravel zamanlayıcısına bağlı değil (bayat muteks eşitlemeyi susturamaz). */
    public function test_masaustunde_esitleme_zamanlayiciya_bagli_degil(): void
    {
        $events = function (): array {
            $schedule = new \Illuminate\Console\Scheduling\Schedule;
            $this->app->instance(\Illuminate\Console\Scheduling\Schedule::class, $schedule);
            \Illuminate\Support\Facades\Schedule::clearResolvedInstances();
            require base_path('routes/schedules/sync.php');

            return collect($schedule->events())->map(fn ($e) => $e->command)->filter()->values()->all();
        };
        $loop = array_values(array_filter($events(), fn ($c) => str_contains($c, 'kurs:sync --loop')));
        $this->assertCount(1, $loop, 'Masaüstü dışı yerel düğümde eski döngü sürer.');

        putenv('KURS_SYNC_DRIVER=desktop');
        $_ENV['KURS_SYNC_DRIVER'] = $_SERVER['KURS_SYNC_DRIVER'] = 'desktop';
        try {
            $this->assertSame([], array_values(array_filter($events(), fn ($c) => str_contains($c, 'kurs:sync'))), 'Masaüstünde kurs:sync zamanlanmamalı.');
        } finally {
            putenv('KURS_SYNC_DRIVER');
            unset($_ENV['KURS_SYNC_DRIVER'], $_SERVER['KURS_SYNC_DRIVER']);
        }
    }
}
