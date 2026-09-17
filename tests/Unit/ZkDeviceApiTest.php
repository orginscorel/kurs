<?php

namespace Tests\Unit;

use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Student;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Köprünün API yüzü: yetki (devices.manage), Türkçe doğrulama mesajları, eşleme CRUD'u,
 * bekleyen (eşleşmemiş) okutmalar ve cihaz durumu. Bellek içi SQLite; canlıya dokunmaz.
 */
class ZkDeviceApiTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        // Kurum veri anahtarı: iletişim şifresi şifreli sütuna yazılır; CI'da .env yok, testte kur.
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

        $this->device = Device::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Ana Giriş', 'kind' => 'fingerprint', 'direction' => 'both',
            'api_token_hash' => str_repeat('b', 64), 'api_token_prefix' => 'dev_api00001', 'is_active' => true,
        ]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    // =================================================================

    public function test_connection_settings_are_saved_and_comm_key_is_never_returned(): void
    {
        $this->asAdmin();

        $this->postJson("/api/v1/attendance/zk/cihazlar/{$this->device->id}/baglanti", [
            'ip' => '192.168.1.50', 'port' => 4370, 'transport' => 'tcp', 'comm_key' => '123456',
        ])->assertOk()->assertJsonPath('durum.ip', '192.168.1.50')->assertJsonPath('durum.sifre_tanimli', true);

        $raw = DB::table('devices')->where('id', $this->device->id)->value('zk_comm_key_encrypted');
        $this->assertNotSame('123456', $raw);

        $response = $this->getJson("/api/v1/attendance/zk/cihazlar/{$this->device->id}/durum")->assertOk();
        $this->assertStringNotContainsString('123456', $response->getContent());
        $response->assertJsonPath('protokol', 'zk')->assertJsonPath('port', 4370);
    }

    public function test_validation_messages_are_in_turkish(): void
    {
        $this->asAdmin();

        $this->postJson("/api/v1/attendance/zk/cihazlar/{$this->device->id}/baglanti", ['ip' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.ip.0', 'Geçerli bir IP adresi girin (ör. 192.168.1.50).');

        $this->postJson("/api/v1/attendance/zk/cihazlar/{$this->device->id}/baglanti", ['ip' => '192.168.1.50', 'comm_key' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.comm_key.0', 'İletişim şifresi yalnız rakamlardan oluşur (cihaz menüsündeki değer).');

        $this->postJson("/api/v1/attendance/zk/cihazlar/{$this->device->id}/baglanti", ['ip' => '192.168.1.50', 'port' => 99999])
            ->assertStatus(422)
            ->assertJsonPath('errors.port.0', 'Port 1 ile 65535 arasında olmalıdır (fabrika değeri 4370).');
    }

    public function test_endpoints_require_devices_manage_permission(): void
    {
        $teacher = User::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Öğretmen', 'username' => 'ogretmen1',
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true,
        ]);
        $teacher->assignRole('ogretmen');

        Sanctum::actingAs($teacher);

        $this->getJson('/api/v1/attendance/zk/eslemeler')->assertStatus(403);
        $this->getJson('/api/v1/attendance/zk/bekleyenler')->assertStatus(403);
        $this->postJson("/api/v1/attendance/zk/cihazlar/{$this->device->id}/baglanti", ['ip' => '192.168.1.50'])->assertStatus(403);
    }

    public function test_mapping_crud(): void
    {
        $this->asAdmin();

        $student = Student::query()->create([
            'branch_id' => $this->branch->id, 'student_no' => '2026001', 'first_name' => 'Ayşe',
            'last_name' => 'Yılmaz', 'full_name' => 'Ayşe Yılmaz', 'status' => 'active',
        ]);

        $this->postJson('/api/v1/attendance/zk/eslemeler', ['kullanici_no' => '1001', 'ogrenci_id' => $student->id])
            ->assertOk()->assertJsonPath('message', 'Eşleme kaydedildi.');

        $this->getJson('/api/v1/attendance/zk/eslemeler')->assertOk()
            ->assertJsonPath('data.0.kullanici_no', '1001')
            ->assertJsonPath('data.0.ogrenci', 'Ayşe Yılmaz');

        $id = DeviceIdentity::query()->withoutGlobalScope('branch')->value('id');
        $this->deleteJson("/api/v1/attendance/zk/eslemeler/{$id}")->assertOk();
        $this->assertSame(0, DeviceIdentity::query()->withoutGlobalScope('branch')->count());
    }

    public function test_mapping_requires_existing_student(): void
    {
        $this->asAdmin();

        $this->postJson('/api/v1/attendance/zk/eslemeler', ['kullanici_no' => '1001', 'ogrenci_id' => 9999])
            ->assertStatus(404)->assertJsonPath('message', 'Öğrenci bu şubede bulunamadı.');

        $this->postJson('/api/v1/attendance/zk/eslemeler', ['kullanici_no' => '1001'])
            ->assertStatus(422)->assertJsonPath('errors.ogrenci_id.0', 'Öğrenci seçilmelidir.');
    }

    public function test_pending_lists_unmatched_reads(): void
    {
        $this->asAdmin();

        AttendanceEvent::query()->create([
            'branch_id' => $this->branch->id, 'device_id' => $this->device->id, 'event_type' => 'ENTRY',
            'source' => 'fingerprint', 'occurred_at' => now(), 'received_at' => now(),
            'idempotency_key' => 'zk:'.$this->device->id.':7777:'.now()->timestamp,
            'raw_identifier' => '7777', 'is_matched' => false,
        ]);

        $this->getJson('/api/v1/attendance/zk/bekleyenler')->assertOk()
            ->assertJsonPath('data.0.kullanici_no', '7777')
            ->assertJsonPath('data.0.cihaz', 'Ana Giriş')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_connection_test_without_settings_explains_what_is_missing(): void
    {
        $this->asAdmin();

        $this->postJson("/api/v1/attendance/zk/cihazlar/{$this->device->id}/test")
            ->assertStatus(422)
            ->assertJsonPath('message', '"Ana Giriş" cihazının IP adresi girilmemiş. Önce cihaz bağlantı bilgilerini kaydedin.');
    }

    public function test_connection_test_returns_actionable_error_instead_of_hanging(): void
    {
        $this->asAdmin();

        $started = microtime(true);
        $response = $this->postJson('/api/v1/attendance/zk/test', ['ip' => '127.0.0.1', 'port' => 1])->assertOk();

        $response->assertJsonPath('durum', 'hata')->assertJsonPath('kod', 'baglanti');
        $this->assertStringContainsString('4370', $response->json('oneri'));
        $this->assertLessThan(15, microtime(true) - $started);
    }
}
