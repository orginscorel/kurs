<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Student;
use App\Models\User;
use App\Services\Devices\Terminal\Data\DeviceUser;
use App\Services\Devices\Terminal\DriverStatus;
use App\Services\Devices\WebPanel\WebPanelDriver;
use App\Support\BranchContext;
use App\Support\Permissions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\NullHandler;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Perkotek YT33 "Dynamic Face" web paneli (/bin/cmd) sürücüsü + uçları.
 * Gerçek cihaza çıkmaz: tüm HTTP Http::fake ile taklit edilir. Kaynaktan doğrulanmış protokol (2026-09-21):
 * result_code==0 başarı, SetUserInfo/EnterEnroll/DeleteUserInfo/GetUserInfo gövdeleri, silme güvenlik kilidi.
 */
class WebPanelTest extends TestCase
{
    private WebPanelDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        config([
            'kurs.silent_events' => true,
            'kurs.data_key' => 'base64:'.base64_encode(random_bytes(32)),
            'logging.channels.terminal' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        ]);

        $this->driver = app(WebPanelDriver::class);
    }

    /** Panel bilgisi girilmiş (kaydedilmemiş) cihaz — sürücü birim testleri için yeterli. */
    private function panelDevice(): Device
    {
        $device = new Device;
        $device->forceFill(['id' => 1, 'branch_id' => 1, 'name' => 'YT33', 'zk_ip' => '192.168.68.60']);
        $device->panel_user = 'admin';
        $device->panel_password = 'admin';
        $device->panel_port = 80;

        return $device;
    }

    /**
     * /bin/cmd'i taklit et. Gerçek cihaz gibi HTTP Digest el sıkışması yapar:
     * Authorization yoksa 401 + challenge; kimlikli yeniden istekte gövdeyi yakalar ve yanıtı döner.
     */
    private function fakeCmd(array $responses, array &$seen): void
    {
        Http::fake(function (Request $request) use ($responses, &$seen) {
            if (! $request->hasHeader('Authorization')) {
                return Http::response('<h1>401 Unauthorized</h1>', 401, [
                    'WWW-Authenticate' => 'Digest realm="Login", qop="auth", nonce="'.md5(uniqid('', true)).'", algorithm=MD5',
                ]);
            }
            $body = json_decode($request->body(), true) ?: [];
            $cmd = $body['cmd'] ?? '';
            $seen[] = $body;
            $payload = $responses[$cmd] ?? ['result_code' => 0, 'result_data' => []];

            return Http::response(json_encode($payload), 200, ['Content-Type' => 'application/json']);
        });
    }

    // ---------------------------------------------------------------- sürücü birim testleri

    public function test_upsert_user_sends_confirmed_setuserinfo_body_without_biometrics(): void
    {
        $seen = [];
        $this->fakeCmd(['SetUserInfo' => ['result_code' => 0, 'result_data' => []]], $seen);

        $r = $this->driver->upsertUser($this->panelDevice(), '9001', 'KURS TEST');

        $this->assertTrue($r->isOk());
        $this->assertCount(1, $seen);
        $user = $seen[0]['data']['users'][0];
        $this->assertSame('SetUserInfo', $seen[0]['cmd']);
        $this->assertSame('9001', $user['userId']);
        $this->assertSame('KURS TEST', $user['name']);
        $this->assertSame(0, $user['privilege']);
        $this->assertSame(1, $user['update']);
        $this->assertSame(0, $user['photoEnroll']);
        // KVKK/güvenlik: biyometrik alanlar ASLA gönderilmez
        foreach (['fps', 'face', 'palm', 'photo'] as $bio) {
            $this->assertArrayNotHasKey($bio, $user, "SetUserInfo gövdesi '{$bio}' içermemeli");
        }
    }

    public function test_device_error_code_maps_to_turkish_message(): void
    {
        $seen = [];
        $this->fakeCmd(['SetUserInfo' => ['result_code' => 1]], $seen);

        $r = $this->driver->upsertUser($this->panelDevice(), '9001', 'KURS TEST');

        $this->assertFalse($r->isOk());
        $this->assertSame(DriverStatus::ProtocolError, $r->status);
        $this->assertStringContainsString('kaydedemedi', $r->message);
    }

    public function test_enter_enroll_only_accepts_fp_or_face(): void
    {
        $seen = [];
        $this->fakeCmd(['EnterEnroll' => ['result_code' => 0]], $seen);

        // Kart cihazda EnterEnroll ile başlatılamaz → cihaza istek gitmemeli
        $bad = $this->driver->enterEnroll($this->panelDevice(), '9001', 'card');
        $this->assertSame(DriverStatus::Unsupported, $bad->status);
        $this->assertCount(0, $seen);

        $ok = $this->driver->enterEnroll($this->panelDevice(), '9001', 'fp');
        $this->assertTrue($ok->isOk());
        $this->assertSame(['userId' => '9001', 'feature' => 'fp'], $seen[0]['data']);
    }

    public function test_delete_users_guards_against_empty_list_and_never_calls_device(): void
    {
        // GÜVENLİK: boş liste = cihazda "tümünü sil" (usersCount:0). Bu ASLA cihaza gitmemeli.
        Http::fake();
        $r = $this->driver->deleteUsers($this->panelDevice(), []);
        $this->assertSame(DriverStatus::Unsupported, $r->status);
        Http::assertNothingSent();
    }

    public function test_delete_users_sends_only_given_numbers(): void
    {
        $seen = [];
        $this->fakeCmd(['DeleteUserInfo' => ['result_code' => 0]], $seen);

        $r = $this->driver->deleteUsers($this->panelDevice(), ['9001', 'abc', '9001']);

        $this->assertTrue($r->isOk());
        $this->assertSame(1, $seen[0]['data']['usersCount']);
        $this->assertSame(['9001'], $seen[0]['data']['usersId']);
    }

    public function test_get_user_parses_counts_and_drops_biometric_payload(): void
    {
        $seen = [];
        $this->fakeCmd(['GetUserInfo' => ['result_code' => 0, 'result_data' => ['users' => [[
            'userId' => '9001', 'name' => 'KURS TEST', 'card' => '0', 'pwd' => '',
            'fps' => ['AAA', 'BBB'], 'face' => 'ZZZZ', 'palm' => [],
        ]]]]], $seen);

        $r = $this->driver->getUser($this->panelDevice(), '9001');

        $this->assertTrue($r->isOk());
        /** @var DeviceUser $u */
        $u = $r->data;
        $this->assertInstanceOf(DeviceUser::class, $u);
        $this->assertSame('9001', $u->deviceUserId);
        $this->assertSame('KURS TEST', $u->name);
        $this->assertSame(2, $u->fingerprintCount);
        $this->assertSame(1, $u->faceCount);
        $this->assertNull($u->cardNo);
        $this->assertFalse($u->passwordExists);
        $this->assertSame([], $u->rawData, 'Ham biyometrik veri saklanmamalı');
    }

    public function test_auth_failure_returns_auth_error(): void
    {
        Http::fake(fn () => Http::response('', 401));
        $r = $this->driver->deviceInfo($this->panelDevice());
        $this->assertSame(DriverStatus::AuthError, $r->status);
    }

    public function test_unconfigured_device_never_calls_network(): void
    {
        Http::fake();
        $device = new Device;
        $device->forceFill(['id' => 2, 'branch_id' => 1, 'name' => 'YT33', 'zk_ip' => '192.168.68.60']);
        $r = $this->driver->deviceInfo($device);
        $this->assertSame(DriverStatus::ProtocolNotImplemented, $r->status);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- uçtan uca (uç + kayıt sihirbazı)

    public function test_settings_save_then_device_start_creates_user_and_enters_enroll(): void
    {
        $device = $this->actingAdminOnLocalNode();

        // Panel ayarını uçtan kaydet (şifre şifreli saklanır, yanıtta dönmez)
        $this->postJson("/api/v1/attendance/pdks/panel/{$device->id}", [
            'ip' => '192.168.68.60', 'port' => 80, 'kullanici' => 'admin', 'sifre' => 'admin',
        ])->assertOk()->assertJsonPath('data.sifre_var', true)->assertJsonMissingPath('data.sifre');

        $this->assertTrue($device->fresh()->supportsWebPanel());

        // Kişi + kayıt oturumu
        $student = Student::query()->create(['branch_id' => $device->branch_id, 'student_no' => '2024001', 'first_name' => 'Deniz', 'last_name' => 'Yıldız', 'status' => 'active']);
        $session = $this->postJson('/api/v1/attendance/pdks/terminal-kayit', ['kisi_turu' => 'student', 'kisi_id' => $student->id])
            ->assertStatus(201)->assertJsonPath('data.panel_var', true)->json('data.id');

        $seen = [];
        $this->fakeCmd([
            'SetUserInfo' => ['result_code' => 0, 'result_data' => []],
            'EnterEnroll' => ['result_code' => 0, 'result_data' => []],
        ], $seen);

        $this->postJson("/api/v1/attendance/pdks/terminal-kayit/{$session}/cihazda-baslat", ['ozellik' => 'face'])
            ->assertOk()->assertJsonPath('data.baslatildi', true);

        $cmds = array_column($seen, 'cmd');
        $this->assertSame(['SetUserInfo', 'EnterEnroll'], $cmds);
        $this->assertSame('face', $seen[1]['data']['feature']);
    }

    public function test_delete_endpoint_removes_only_given_user(): void
    {
        $device = $this->actingAdminOnLocalNode();
        $device->panel_user = 'admin';
        $device->panel_password = 'admin';
        $device->panel_port = 80;
        $device->save();

        DB::table('terminal_device_users')->insert([
            'branch_id' => $device->branch_id, 'device_id' => $device->id, 'user_no' => '9001',
            'name' => 'KURS TEST', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $seen = [];
        $this->fakeCmd(['DeleteUserInfo' => ['result_code' => 0]], $seen);

        $this->deleteJson("/api/v1/attendance/pdks/panel/{$device->id}/kullanici/9001")->assertOk();

        $this->assertSame('DeleteUserInfo', $seen[0]['cmd']);
        $this->assertSame(['9001'], $seen[0]['data']['usersId']);
        $this->assertDatabaseMissing('terminal_device_users', ['device_id' => $device->id, 'user_no' => '9001']);
    }

    public function test_probe_command_adds_verifies_and_cleans_up_test_user(): void
    {
        $device = $this->actingAdminOnLocalNode();
        $device->panel_user = 'admin';
        $device->panel_password = 'admin';
        $device->panel_port = 80;
        $device->save();

        $seen = [];
        $exists = false;
        Http::fake(function (Request $request) use (&$seen, &$exists) {
            if (! $request->hasHeader('Authorization')) {
                return Http::response('401', 401, ['WWW-Authenticate' => 'Digest realm="Login", qop="auth", nonce="'.md5(uniqid('', true)).'", algorithm=MD5']);
            }
            $body = json_decode($request->body(), true) ?: [];
            $seen[] = $body['cmd'];
            $rd = [];
            if ($body['cmd'] === 'GetDeviceInfo') {
                $rd = ['name' => 'YT33', 'userCount' => 5];
            } elseif ($body['cmd'] === 'SetUserInfo') {
                $exists = true;
            } elseif ($body['cmd'] === 'DeleteUserInfo') {
                $exists = false;
            } elseif ($body['cmd'] === 'GetUserInfo') {
                $rd = ['users' => $exists ? [['userId' => '9001', 'name' => 'KURS TEST']] : []];
            }

            return Http::response(json_encode(['result_code' => 0, 'result_data' => $rd]), 200);
        });

        $this->artisan('kurs:cihaz-panel-dene', ['--device' => $device->id])->assertSuccessful();

        // Sıra: künye → boş mu → ekle → doğrula → sil → temiz mi
        $this->assertSame(['GetDeviceInfo', 'GetUserInfo', 'SetUserInfo', 'GetUserInfo', 'DeleteUserInfo', 'GetUserInfo'], $seen);
    }

    public function test_probe_command_aborts_when_number_already_used(): void
    {
        $device = $this->actingAdminOnLocalNode();
        $device->panel_user = 'admin';
        $device->panel_password = 'admin';
        $device->panel_port = 80;
        $device->save();

        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            if (! $request->hasHeader('Authorization')) {
                return Http::response('401', 401, ['WWW-Authenticate' => 'Digest realm="Login", qop="auth", nonce="'.md5(uniqid('', true)).'", algorithm=MD5']);
            }
            $body = json_decode($request->body(), true) ?: [];
            $seen[] = $body['cmd'];
            $rd = $body['cmd'] === 'GetUserInfo' ? ['users' => [['userId' => '9001', 'name' => 'Gerçek Kişi']]] : ['name' => 'YT33'];

            return Http::response(json_encode(['result_code' => 0, 'result_data' => $rd]), 200);
        });

        $this->artisan('kurs:cihaz-panel-dene', ['--device' => $device->id])->assertFailed();

        // Dolu numarada ASLA SetUserInfo/DeleteUserInfo çağrılmamalı
        $this->assertNotContains('SetUserInfo', $seen);
        $this->assertNotContains('DeleteUserInfo', $seen);
    }

    private function actingAdminOnLocalNode(): Device
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($branch->id);
        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::query()->create([
            'branch_id' => $branch->id, 'name' => 'Müdür', 'username' => 'mudur1',
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true,
        ]);
        $admin->assignRole('yonetici');
        Sanctum::actingAs($admin);
        config(['kurs.node' => 'local']);

        return Device::query()->create([
            'branch_id' => $branch->id, 'name' => 'YT33', 'kind' => 'face', 'direction' => 'both',
            'zk_ip' => '192.168.68.60', 'api_token_hash' => str_repeat('c', 64), 'api_token_prefix' => 'dev_api00002', 'is_active' => true,
        ]);
    }
}
