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
 * Sunucu "yeniden anlık görüntü" dediğinde (değişiklik günlüğü budandı ya da veriler sıfırlandı):
 *  - yerel düğüm kurulum ekranı açtırmadan tam görüntüyü kendi turunda alır,
 *  - sunucuda ARTIK OLMAYAN satırlar yerelden de silinir (yoksa ilk düzenlemede geri gönderilir),
 *  - henüz gönderilmemiş yerel kayıt (çevrimdışı açılmış ön kayıt) SİLİNMEZ.
 * Bellek içi SQLite üzerinde gerçek migration'lar (canlı veritabanına dokunmaz).
 */
class SyncResnapshotTest extends TestCase
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

        config([
            'kurs.node' => 'local',
            'sync.device_code' => 'D7',
            'sync.server_url' => 'https://ornek-sunucu.test',
            'sync.device_token' => 'test-cihaz-jetonu',
        ]);
        app(\App\Sync\RowCodec::class)->forget();
        app(LocalState::class)->put('snapshot_done_at', now()->toIso8601String());
        app(LocalState::class)->put('device_code', 'D7');
    }

    protected function tearDown(): void
    {
        config(['kurs.node' => 'server', 'sync.server_url' => null, 'sync.device_token' => null]);
        parent::tearDown();
    }

    /** Sunucu boşaltılmış: çekme "yeniden görüntü" der, anlık görüntü hiçbir satır vermez. */
    private function fakeBosSunucu(): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
        Http::fake([
            '*/sync/pull*' => Http::response(['changes' => [], 'cursor' => 0, 'more' => false, 'resnapshot' => true]),
            '*/sync/push' => Http::response(['results' => [], 'accepted' => 0]),
            '*/sync/snapshot' => Http::response(['cursor' => 0, 'server_time' => now()->toIso8601String(), 'tables' => [
                ['table' => 'leads', 'kind' => 'keyed', 'pivot' => false, 'rows' => 0],
            ]]),
            '*/sync/snapshot/*' => Http::response(['table' => 'leads', 'rows' => [], 'next' => null]),
            '*/sync/status*' => Http::response(['open_conflicts' => 0, 'data_key' => null]),
            '*/sync/files/manifest*' => Http::response(['files' => [], 'next' => null, 'more' => false]),
            '*/sync/number-blocks' => Http::response(['blocks' => []]),
        ]);
    }

    public function test_sunucu_bosaltilinca_yerel_artik_satirlar_silinir_bekleyen_kayit_kalir(): void
    {
        // (1) Sunucudan inmiş gibi duran, gönderilmesi bitmiş bir ön kayıt
        $eski = app(LeadService::class)->create([
            'first_name' => 'Eski', 'last_name' => 'Demo', 'phone' => '05321112233', 'source' => 'other',
        ]);
        DB::table('sync_changes')->update(['status' => 'pushed']);
        app(LocalState::class)->put('pushed_up_to', (int) DB::table('sync_changes')->max('id'));

        // (2) Çevrimdışı açılmış, HENÜZ GÖNDERİLMEMİŞ ön kayıt
        $bekleyen = app(LeadService::class)->create([
            'first_name' => 'Yeni', 'last_name' => 'Kayıt', 'phone' => '05324445566', 'source' => 'other',
        ]);
        $this->assertGreaterThan(0, app(LocalState::class)->pendingCount());

        $this->fakeBosSunucu();

        // İlk tur: sunucu yeniden görüntü istedi → kurulum ekranı değil, kendi kendine işaretler
        $ilk = app(LocalSyncEngine::class)->cycle(true);
        $this->assertSame('error', $ilk['status']);
        $this->assertNull(app(LocalState::class)->get('snapshot_done_at'));
        $this->assertNotNull(app(LocalState::class)->get('resnapshot_at'));

        // İkinci tur: tam görüntüyü kendisi alır
        $ikinci = app(LocalSyncEngine::class)->cycle(true);
        $this->assertSame('resnapshot', $ikinci['status'], 'Yeniden görüntü tur içinde alınmalı (kurulum istenmemeli).');
        $this->assertNotNull(app(LocalState::class)->get('snapshot_done_at'));
        $this->assertNull(app(LocalState::class)->get('resnapshot_at'));

        // Sunucuda olmayan satır yerelden de gitti; bekleyen kayıt duruyor
        $this->assertDatabaseMissing('leads', ['uuid' => $eski->uuid]);
        $this->assertDatabaseHas('leads', ['uuid' => $bekleyen->uuid]);
        $this->assertGreaterThanOrEqual(1, $ikinci['snapshot']['silinen']);
    }

    /** Eski sürüm "yeniden görüntü" işareti yazmadan kaldıysa: çalışmış bir düğüm kurulum ekranına düşmemeli. */
    public function test_isaret_olmadan_da_calismis_dugum_kendiliginden_goruntu_alir(): void
    {
        app(LeadService::class)->create([
            'first_name' => 'Eski', 'last_name' => 'Demo', 'phone' => '05321112233', 'source' => 'other',
        ]);
        DB::table('sync_changes')->update(['status' => 'pushed']);
        app(LocalState::class)->put('pushed_up_to', (int) DB::table('sync_changes')->max('id'));

        // Eski sürümün bıraktığı durum: görüntü yok, işaret yok, ama düğüm daha önce eşitlemiş
        app(LocalState::class)->put('snapshot_done_at', null);
        app(LocalState::class)->put('resnapshot_at', null);
        app(LocalState::class)->put('last_success_at', now()->subHour()->toIso8601String());

        $this->fakeBosSunucu();
        $out = app(LocalSyncEngine::class)->cycle(true);

        $this->assertSame('resnapshot', $out['status'], 'Kurulum ekranı değil, kendiliğinden tam görüntü beklenir.');
        $this->assertNotNull(app(LocalState::class)->get('snapshot_done_at'));
        $this->assertSame(0, DB::table('leads')->count(), 'Sunucu boş: yerel ön kayıtlar da silinmeli.');
    }
}
