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
use Tests\TestCase;

/**
 * Masaüstü yerel kurulumu İNTERNET YOKKEN çalışmalı:
 *  - ön kayıt (lead) eklemek dış ağa hiç çıkmadan başarılı olmalı ve kuyruğa yazılmalı,
 *  - eşitleme turu çevrimdışı hata verdiğinde kuyruk KAYBOLMAMALI ve yeniden deneme kısa sürede olmalı,
 *  - bağlantı gelince kuyruk kendiliğinden gitmeli (ikincil adımlar hata verse bile).
 * Bellek içi SQLite üzerinde gerçek migration'lar (canlı veritabanına dokunmaz).
 */
class SyncOfflineTest extends TestCase
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

    public function test_cevrimdisi_on_kayit_eklenebilir_ve_kuyruga_yazilir(): void
    {
        // Dış ağa çıkan HER istek patlasın: yazma yolu ağa hiç dokunmamalı
        Http::preventStrayRequests();
        Http::fake();

        $lead = app(LeadService::class)->create([
            'first_name' => 'Ayşe', 'last_name' => 'Demir', 'phone' => '05321112233', 'source' => 'other',
        ]);

        $this->assertTrue($lead->exists);
        $this->assertSame('new', $lead->stage);
        Http::assertNothingSent();

        $queued = DB::table('sync_changes')->where('table_name', 'leads')->whereNull('status')->get();
        $this->assertCount(1, $queued, 'Ön kayıt eşitleme kuyruğuna yazılmalı.');
        $this->assertSame('insert', $queued->first()->op);
        $this->assertSame($lead->uuid, $queued->first()->row_uuid);
        $this->assertGreaterThan(0, app(LocalState::class)->pendingCount());
    }

    public function test_cevrimdisi_eslesme_turu_kuyrugu_kaybetmez_ve_kisa_surede_yeniden_dener(): void
    {
        app(LeadService::class)->create(['first_name' => 'Ayşe', 'last_name' => 'Demir', 'phone' => '05321112233', 'source' => 'other']);
        $pending = app(LocalState::class)->pendingCount();
        $this->assertGreaterThan(0, $pending);

        $this->fakeOffline();
        $out = app(LocalSyncEngine::class)->cycle(true);

        $this->assertSame('offline', $out['status']);
        $this->assertSame($pending, app(LocalState::class)->pendingCount(), 'Çevrimdışı tur kuyruğu tüketmemeli.');

        $file = app(LocalState::class)->file();
        $this->assertSame('offline', $file['phase']);
        $wait = strtotime($file['next_attempt_at']) - time();
        $this->assertLessThanOrEqual(30, $wait, 'Çevrimdışı yeniden deneme dakikalarca ertelenmemeli.');

        // Arka arkaya hatalar beklemeyi büyütmemeli (bağlantı gelince kuyruk hemen gitsin)
        for ($i = 0; $i < 6; $i++) {
            app(LocalSyncEngine::class)->cycle(true);
        }
        $file = app(LocalState::class)->file();
        $this->assertLessThanOrEqual(30, strtotime($file['next_attempt_at']) - time());
    }

    public function test_baglanti_gelince_kuyruk_gonderilir_ikincil_adim_hata_verse_de(): void
    {
        $lead = app(LeadService::class)->create(['first_name' => 'Ayşe', 'last_name' => 'Demir', 'phone' => '05321112233', 'source' => 'other']);

        $this->fakeOffline();
        $this->assertSame('offline', app(LocalSyncEngine::class)->cycle(true)['status']);

        $queued = DB::table('sync_changes')->whereNull('status')->orderBy('id')->get();
        $this->assertGreaterThan(0, $queued->count());

        // Bağlantı geri geldi. Numara bloğu ucu hâlâ hata veriyor; buna rağmen kuyruk gitmeli.
        $this->fakeOnline($queued->map(fn ($r) => ['id' => $r->change_uuid, 'status' => 'accepted'])->all());
        $out = app(LocalSyncEngine::class)->cycle(true);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(0, app(LocalState::class)->pendingCount(), 'Bağlantı gelince kuyruk boşalmalı.');
        $this->assertSame('pushed', DB::table('sync_changes')->where('row_uuid', $lead->uuid)->value('status'));
        $this->assertNotNull(app(LocalState::class)->get('last_success_at'));
        $this->assertSame('idle', app(LocalState::class)->file()['phase']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sync/push'));
    }

    public function test_durum_yoklamasi_basarisiz_olsa_da_gonderilen_kuyruk_basarili_sayilir(): void
    {
        app(LeadService::class)->create(['first_name' => 'Ayşe', 'last_name' => 'Demir', 'phone' => '05321112233', 'source' => 'other']);
        $queued = DB::table('sync_changes')->whereNull('status')->orderBy('id')->get();

        Http::fake([
            '*/sync/push' => Http::response(['results' => $queued->map(fn ($r) => ['id' => $r->change_uuid, 'status' => 'accepted'])->all()]),
            '*/sync/pull*' => Http::response(['changes' => [], 'cursor' => 0, 'more' => false]),
            '*/sync/files/manifest*' => Http::response(['files' => [], 'next' => null, 'more' => false]),
            '*/sync/number-blocks' => Http::response(['message' => 'yok'], 422),
            // Tur bittikten sonra bağlantı koptu
            '*/sync/status*' => fn () => throw new ConnectionException('offline'),
        ]);

        $out = app(LocalSyncEngine::class)->cycle(true);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(0, app(LocalState::class)->pendingCount());
        $this->assertNotNull(app(LocalState::class)->get('last_success_at'));
    }
}
