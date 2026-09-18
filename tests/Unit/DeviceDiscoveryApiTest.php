<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeZkDevice;
use Tests\TestCase;

/**
 * "AĞDA CİHAZ BUL" uçlarının API yüzü: yetki, Türkçe doğrulama, TEK KAYNAK kuralı
 * (aynı cihaz iki kez eklenmez) ve teşhis satırındaki çözüm önerisi.
 *
 * Bellek içi SQLite; canlıya dokunmaz, dış ağa çıkılmaz.
 */
class DeviceDiscoveryApiTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private User $teacher;

    /** @var list<array{0:mixed,1:array}> */
    private array $running = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        config(['kurs.silent_events' => true, 'kurs.data_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->artisan('migrate', ['--force' => true])->run();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        Role::findOrCreate('ogretmen', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Müdür', 'username' => 'mudur1',
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true,
        ]);
        $this->admin->assignRole('yonetici');

        $this->teacher = User::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Öğretmen', 'username' => 'ogretmen1',
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true,
        ]);
        $this->teacher->assignRole('ogretmen');
        // Terminal yazma/bağlantı uçları yalnız masaüstünde çalışır (EnsureTerminalDesktop); bu testler masaüstü düğümünü sınar
        config(['kurs.node' => 'local']);
    }

    protected function tearDown(): void
    {
        foreach ($this->running as [$process, $pipes]) {
            FakeZkDevice::stop($process, $pipes);
        }
        $this->running = [];
        parent::tearDown();
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    // ================================================================= yetki

    public function test_discovery_endpoints_require_device_permission(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/v1/attendance/devices/kesif/ortam')->assertForbidden();
        $this->postJson('/api/v1/attendance/devices/kesif/tara')->assertForbidden();
        $this->getJson('/api/v1/attendance/devices/teshis')->assertForbidden();
    }

    // ================================================================= ortam bilgisi

    public function test_environment_warns_clearly_when_opened_on_the_web_server(): void
    {
        $this->asAdmin();
        config(['kurs.node' => 'server']);

        $response = $this->getJson('/api/v1/attendance/devices/kesif/ortam')->assertOk();

        $response->assertJsonPath('yerel_dugum', false);
        $this->assertStringContainsString('WEB SUNUCUSUNDA', (string) $response->json('uyari'));
        $this->assertStringContainsString('masaüstü uygulamasından', (string) $response->json('uyari'));
    }

    // ================================================================= protokol kataloğu

    public function test_protocol_catalog_lists_written_and_planned_drivers(): void
    {
        $this->asAdmin();

        $data = $this->getJson('/api/v1/attendance/devices/protokoller')->assertOk()->json('data');
        $byKey = collect($data)->keyBy('anahtar');

        $this->assertSame('hazir', $byKey['zk']['durum']);
        $this->assertTrue($byKey['zk']['yetenekler']['tarama']);
        $this->assertSame('hazir', $byKey['adms']['durum']);
        $this->assertTrue($byKey['adms']['yetenekler']['itme']);
        $this->assertSame('yakinda', $byKey['hikvision']['durum']);
        $this->assertSame('yakinda', $byKey['anviz']['durum']);

        // Form alanları sunucudan gelir; ön yüzde ham JSON kutusu gerekmez.
        $this->assertNotEmpty($byKey['zk']['alanlar']);
        $this->assertContains('ip', array_column($byKey['zk']['alanlar'], 'ad'));
        $this->assertNotEmpty($byKey['zk']['kurulum']);
    }

    // ================================================================= tek kaynak kuralı

    public function test_registering_the_same_address_twice_updates_instead_of_duplicating(): void
    {
        $this->asAdmin();

        $payload = [
            'protokol' => 'zk', 'ad' => 'Ana giriş terminali', 'yon' => 'both',
            'ip' => '192.168.1.50', 'port' => 4370, 'transport' => 'tcp',
            'seri_no' => 'YT33-TEST-0001', 'model' => 'YT33', 'marka' => 'Perkotek',
        ];

        $first = $this->postJson('/api/v1/attendance/devices/kesif/ekle', $payload)->assertOk();
        $first->assertJsonPath('yeni_mi', true);

        $second = $this->postJson('/api/v1/attendance/devices/kesif/ekle', $payload + ['ad' => 'Ana giriş terminali'])->assertOk();
        $second->assertJsonPath('yeni_mi', false);

        $this->assertSame(1, Device::query()->withoutGlobalScope('branch')->count(), 'Aynı cihaz iki kez eklenmemeli.');

        $device = Device::query()->withoutGlobalScope('branch')->first();
        $this->assertSame('zk', $device->protocol);
        $this->assertSame('192.168.1.50', $device->zk_ip);
        $this->assertSame('YT33', $device->device_model);
        $this->assertSame('Perkotek', $device->vendor);
        $this->assertNotNull($device->discovered_at);
    }

    public function test_registration_validation_messages_are_in_turkish(): void
    {
        $this->asAdmin();

        $this->postJson('/api/v1/attendance/devices/kesif/ekle', ['protokol' => 'zk', 'yon' => 'both'])
            ->assertStatus(422)
            ->assertJsonPath('errors.ad.0', 'Cihaza bir ad verin (ör. "Ana giriş parmak izi").')
            ->assertJsonPath('errors.ip.0', 'ZKTeco protokolünde cihazın IP adresi zorunludur.');

        $this->postJson('/api/v1/attendance/devices/kesif/ekle', ['protokol' => 'adms', 'ad' => 'ADMS cihaz', 'yon' => 'both'])
            ->assertStatus(422)
            ->assertJsonPath('errors.seri_no.0', 'ADMS protokolünde cihazın seri numarası zorunludur (cihaz kendini bununla tanıtır).');
    }

    // ================================================================= teşhis

    public function test_diagnostics_explain_a_missing_ip_and_a_wrong_password_in_turkish(): void
    {
        $this->asAdmin();

        $bare = Device::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Kurulmamış terminal', 'kind' => 'fingerprint',
            'direction' => 'both', 'protocol' => 'zk', 'api_token_hash' => 'a1', 'api_token_prefix' => 'a1', 'is_active' => true,
        ]);

        $broken = Device::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Şifresi yanlış terminal', 'kind' => 'fingerprint',
            'direction' => 'both', 'protocol' => 'zk', 'zk_ip' => '192.168.1.51',
            'api_token_hash' => 'a2', 'api_token_prefix' => 'a2', 'is_active' => true,
        ]);
        $broken->forceFill([
            'zk_last_pull_at' => now(), 'zk_last_status' => 'error',
            'zk_last_error' => '[kimlik] Cihaz iletişim şifresini reddetti.',
        ])->save();

        $rows = collect($this->getJson('/api/v1/attendance/devices/teshis')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('kurulmadi', $rows[$bare->id]['durum']);
        $this->assertStringContainsString('Ağda cihaz bul', $rows[$bare->id]['cozum']);

        $this->assertSame('hata', $rows[$broken->id]['durum']);
        $this->assertStringContainsString('İletişim Şifresi', $rows[$broken->id]['cozum']);
        $this->assertSame('ZKTeco protokolü (4370)', $rows[$broken->id]['protokol_etiketi']);
    }

    // ================================================================= tek adres deneme

    public function test_probe_reads_the_identity_of_a_real_listening_terminal(): void
    {
        $this->asAdmin();

        [$process, $port, $pipes] = FakeZkDevice::start(['records' => 3]);
        $this->running[] = [$process, $pipes];

        $this->postJson('/api/v1/attendance/devices/kesif/dene', ['ip' => '127.0.0.1', 'port' => $port])
            ->assertOk()
            ->assertJsonPath('kunye_okundu', true)
            ->assertJsonPath('seri_no', 'YT33-TEST-0001')
            ->assertJsonPath('model', 'YT33')
            ->assertJsonPath('kayitli_mi', false);
    }
}
