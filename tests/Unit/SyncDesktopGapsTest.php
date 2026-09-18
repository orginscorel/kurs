<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\BranchContext;
use App\Support\Permissions;
use App\Support\Sensitive;
use App\Sync\ChangeRecorder;
use App\Sync\Local\LocalApplier;
use App\Sync\Local\LocalState;
use App\Sync\Local\LocalSyncEngine;
use App\Sync\Local\NotificationReadReporter;
use App\Sync\Local\TerminalStatusReporter;
use App\Sync\Models\SyncDevice;
use App\Sync\RowCodec;
use App\Sync\Server\PullService;
use App\Sync\Server\PushService;
use App\Sync\Server\SnapshotService;
use App\Sync\Server\SyncReject;
use App\Sync\Sweeper;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Masaüstünde eksik kalan bölümler (1.12.3): terminal kaydı (devices) + eşlemeler (device_identities) iki yönlü ama
 * yalnız masaüstünde yazılır; düğüme özel sütunlar taşınmaz; terminal durumu ayrı raporla web'e çıkar; bildirimler
 * masaüstüne iner, okundu bilgisi yukarı gider; sağlayıcı/webhook/iCal sırları cihaza İNMEZ.
 * Bellek içi SQLite (gerçek migration'lar); canlı veritabanına dokunmaz.
 */
class SyncDesktopGapsTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private SyncDevice $device;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['kurs.silent_events' => true, 'kurs.node' => 'server', 'sync.sweep_before_pull_seconds' => 0,
            'kurs.data_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->artisan('migrate', ['--force' => true])->run();
        app(SyncSchema::class)->flush();
        app(ChangeRecorder::class)->reset();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);
        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        Role::findOrCreate('danisman', 'web')->syncPermissions(['sync.use', 'students.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = $this->makeUser('mudur1', 'staff', 'yonetici');
        Auth::setUser($this->admin);
        app(SyncSchema::class)->backfillUuids();
        app(SyncSchema::class)->flush();
        app(Sweeper::class)->baseline();

        $token = $this->admin->createToken('sync:test', ['sync']);
        $this->deviceToken = $token->plainTextToken;
        $this->device = SyncDevice::query()->create([
            'uuid' => (string) Str::uuid7(), 'branch_id' => $this->branch->id, 'user_id' => $this->admin->id,
            'code' => 'D1', 'name' => 'Ofis Mac', 'platform' => 'macos', 'token_id' => $token->accessToken->id,
            'status' => 'active', 'paired_at' => now(),
        ]);
    }

    private function makeUser(string $username, string $type, ?string $role = null): User
    {
        $u = User::query()->create(['branch_id' => $this->branch->id, 'name' => ucfirst($username), 'username' => $username,
            'user_type' => $type, 'password' => 'Parola123!', 'is_active' => true]);
        if ($role) {
            $u->assignRole($role);
        }

        return $u;
    }

    private function terminal(array $extra = []): Device
    {
        $d = new Device;
        $d->forceFill(array_merge([
            'branch_id' => $this->branch->id, 'name' => 'Ana Giriş', 'kind' => 'fingerprint', 'direction' => 'both',
            'api_token_hash' => str_repeat('a', 64), 'api_token_prefix' => 'dev_sunucu01', 'is_active' => true,
            'protocol' => 'zk', 'zk_ip' => '192.168.1.50', 'zk_port' => 4370, 'serial_no' => 'YT33-0001',
            'zk_cursor_at' => now(), 'zk_last_pull_at' => now(), 'zk_last_status' => 'ok', 'last_seen_at' => now(),
        ], $extra));
        $d->zk_comm_key = '123456';
        $d->save();

        return $d->refresh();
    }

    private function asDevice()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->deviceToken);
    }

    // ================================================================== kayıt defteri

    public function test_registry_classes_and_excluded_columns(): void
    {
        $devices = SyncRegistry::get('devices');
        $this->assertSame(SyncRegistry::REFERENCE, $devices->kind);
        $this->assertTrue($devices->isPushable() && $devices->isPullable(), 'terminal kaydı iki yönlü');
        foreach (['api_token_hash', 'api_token_prefix', 'zk_cursor_at', 'zk_cursor_key', 'zk_last_pull_at', 'zk_last_status',
            'zk_last_error', 'zk_last_record_count', 'last_seen_at', 'last_ip', 'adms_stamp'] as $col) {
            $this->assertTrue($devices->excludes($col), "$col düğüme özel olmalı");
        }
        $this->assertFalse($devices->excludes('zk_comm_key_encrypted'));
        $this->assertSame(SyncRegistry::REFERENCE, SyncRegistry::get('device_identities')->kind);
        $this->assertSame(SyncRegistry::SERVER, SyncRegistry::get('app_notifications')->kind);
        foreach (['integrations', 'webhooks', 'webhook_deliveries', 'calendar_feeds', 'push_tokens', 'outbound_messages', 'sync_terminal_reports'] as $t) {
            $this->assertFalse(SyncRegistry::get($t)->isSynced(), "$t cihaza inmemeli");
        }
        // fill: boş bırakılamayan hariç sütun alıcıda yeni değer alır
        $row = $devices->withFill(['name' => 'x']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['api_token_hash']);
        $this->assertSame('', $row['api_token_prefix']);
    }

    // ================================================================== devices: iki yön + hariç sütunlar

    public function test_terminal_goes_down_without_node_columns_and_comm_key_stays_encrypted(): void
    {
        $t = $this->terminal();
        $this->assertNotEmpty($t->uuid);
        $raw = DB::table('devices')->where('id', $t->id)->value('zk_comm_key_encrypted');
        $this->assertNotSame('123456', $raw);
        $this->assertSame('123456', Sensitive::decrypt($raw), 'kurum veri anahtarıyla şifreli');

        $ins = DB::table('sync_changes')->where('table_name', 'devices')->where('row_uuid', $t->uuid)->where('op', 'insert')->first();
        $fields = json_decode($ins->fields, true);
        foreach (['api_token_hash', 'api_token_prefix', 'zk_cursor_at', 'zk_last_pull_at', 'zk_last_status', 'last_seen_at'] as $c) {
            $this->assertArrayNotHasKey($c, $fields, "$c günlüğe/tele girmez");
        }
        $this->assertSame('192.168.1.50', $fields['zk_ip']);
        $this->assertSame($raw, $fields['zk_comm_key_encrypted'], 'şifreli metin olduğu gibi taşınır');

        // Yalnız düğüme özel sütun değişirse günlüğe hiçbir şey yazılmaz (köprü dakikada bir yazar)
        $before = (int) DB::table('sync_changes')->max('id');
        $t->forceFill(['zk_last_pull_at' => now()->addMinute(), 'zk_last_record_count' => 9, 'zk_cursor_at' => now()->addMinute()])->save();
        DB::table('devices')->where('id', $t->id)->update(['last_seen_at' => now()->addMinutes(2)]);
        app(Sweeper::class)->sweep(['devices'], true);
        $this->assertSame($before, (int) DB::table('sync_changes')->max('id'));

        // Anlık görüntü: şifre yalnız devices.manage ile iner, jeton özeti hiç inmez
        $page = app(SnapshotService::class)->page($this->device, $this->admin, 'devices', 0, 100);
        $this->assertArrayHasKey('zk_comm_key_encrypted', $page['rows'][0]['fields']);
        $this->assertArrayNotHasKey('api_token_hash', $page['rows'][0]['fields']);
        $limited = $this->makeUser('ayse', 'staff', 'danisman');
        $page = app(SnapshotService::class)->page($this->device, $limited, 'devices', 0, 100);
        $this->assertArrayNotHasKey('zk_comm_key_encrypted', $page['rows'][0]['fields']);

        // Mac'e iniş: yeni satır kendi (kullanılamaz) jeton özetini alır, imleç/durum boş başlar
        DB::table('devices')->delete();
        app(LocalApplier::class)->apply(['table' => 'devices', 'row' => $t->uuid, 'op' => 'upsert', 'fields' => $fields]);
        $local = DB::table('devices')->where('uuid', $t->uuid)->first();
        $this->assertSame('192.168.1.50', $local->zk_ip);
        $this->assertNotSame(str_repeat('a', 64), $local->api_token_hash);
        $this->assertSame('', $local->api_token_prefix);
        $this->assertNull($local->zk_cursor_at);
        $this->assertSame('123456', Device::query()->withoutGlobalScopes()->find($local->id)->zk_comm_key);
    }

    public function test_terminal_created_on_mac_is_pushed_and_merged_by_serial(): void
    {
        $server = $this->terminal(['serial_no' => 'YT33-7777', 'name' => 'Web kaydı']);
        $uuid = (string) Str::uuid7();
        $r = app(PushService::class)->push($this->device, [[
            'id' => (string) Str::uuid7(), 'table' => 'devices', 'op' => 'insert', 'row' => $uuid, 'at' => now()->toIso8601String(),
            'fields' => ['name' => 'Mac kaydı', 'kind' => 'fingerprint', 'direction' => 'both', 'is_active' => 1, 'protocol' => 'zk',
                'zk_ip' => '10.0.0.9', 'zk_port' => 4370, 'zk_transport' => 'tcp', 'serial_no' => 'YT33-0002',
                'api_token_hash' => 'KOTU', 'zk_last_status' => 'ok'],   // hariç sütunlar tel üzerinde gelse de yok sayılır
        ]], (int) DB::table('sync_changes')->max('id'), now()->toIso8601String());
        $this->assertSame('accepted', $r['results'][0]['status']);
        $row = DB::table('devices')->where('uuid', $uuid)->first();
        $this->assertSame('10.0.0.9', $row->zk_ip);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->api_token_hash);
        $this->assertNull($row->zk_last_status);

        // Aynı seri no'lu terminal iki yerde ayrı eklendiyse tek kayıtta birleşir
        $alias = (string) Str::uuid7();
        $r = app(PushService::class)->push($this->device, [[
            'id' => (string) Str::uuid7(), 'table' => 'devices', 'op' => 'insert', 'row' => $alias, 'at' => now()->toIso8601String(),
            'fields' => ['name' => 'Mac kaydı 2', 'kind' => 'fingerprint', 'direction' => 'both', 'serial_no' => 'YT33-7777', 'is_active' => 1],
        ]], (int) DB::table('sync_changes')->max('id'), now()->toIso8601String());
        $this->assertSame($server->uuid, $r['results'][0]['row']);
        $this->assertSame(2, DB::table('devices')->count());
    }

    public function test_identity_mapping_goes_up_from_mac(): void
    {
        $student = new \App\Models\Student;
        $student->forceFill(['student_no' => '100200', 'first_name' => 'Ali', 'last_name' => 'Kaya', 'status' => 'active'])->save();
        $uuid = (string) Str::uuid7();
        $r = app(PushService::class)->push($this->device, [[
            'id' => (string) Str::uuid7(), 'table' => 'device_identities', 'op' => 'insert', 'row' => $uuid, 'at' => now()->toIso8601String(),
            'fields' => ['kind' => 'fingerprint', 'identifier' => '1001', 'person_type' => 'student', 'person_id' => $student->refresh()->uuid, 'is_active' => 1],
        ]], (int) DB::table('sync_changes')->max('id'), now()->toIso8601String());
        $this->assertSame('accepted', $r['results'][0]['status']);
        $this->assertSame((int) $student->id, (int) DB::table('device_identities')->where('uuid', $uuid)->value('person_id'));
    }

    // ================================================================== web salt okunur

    public function test_web_rejects_terminal_writes_but_allows_reading_and_qr(): void
    {
        $t = $this->terminal();
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/attendance/devices', ['name' => 'X', 'kind' => 'fingerprint', 'direction' => 'both'])
            ->assertStatus(409)->assertJsonPath('error_code', 'terminal_desktop_only');
        $this->putJson("/api/v1/attendance/devices/{$t->id}", ['name' => 'X', 'kind' => 'fingerprint', 'direction' => 'both'])->assertStatus(409);
        $this->deleteJson("/api/v1/attendance/devices/{$t->id}")->assertStatus(409);
        $this->postJson("/api/v1/attendance/zk/cihazlar/{$t->id}/baglanti", ['ip' => '192.168.1.60'])->assertStatus(409);
        $this->postJson("/api/v1/attendance/zk/cihazlar/{$t->id}/test")->assertStatus(409);
        $this->postJson("/api/v1/attendance/zk/cihazlar/{$t->id}/cek")->assertStatus(409);
        $this->postJson('/api/v1/attendance/zk/eslemeler', ['kullanici_no' => '5', 'ogrenci_id' => 1])->assertStatus(409);
        $this->postJson('/api/v1/attendance/devices/kesif/tara', [])->assertStatus(409);
        $this->postJson('/api/v1/attendance/identities', ['student_id' => 1, 'kind' => 'fingerprint', 'identifier' => '9'])->assertStatus(409);
        // QR kimliği web'de yönetilebilir (doğrulamaya kadar gider)
        $this->assertNotSame(409, $this->postJson('/api/v1/attendance/identities', ['student_id' => 999999, 'kind' => 'qr', 'identifier' => 'Q1'])->status());
        $this->assertSame('192.168.1.50', DB::table('devices')->where('id', $t->id)->value('zk_ip'), 'hiçbir şey değişmedi');

        // Okuma açık; IP ve iletişim şifresi web yanıtında HİÇ yok
        $res = $this->getJson('/api/v1/attendance/devices')->assertOk();
        $this->assertFalse($res->json('terminal_writes'));
        $body = $res->getContent();
        $this->assertStringNotContainsString('192.168.1.50', $body);
        $this->assertStringNotContainsString('zk_comm_key', $body);
        $this->assertNull($res->json('data.0.bridge'), 'henüz rapor yok');
    }

    // ================================================================== terminal durum raporu

    public function test_terminal_status_report_reaches_web_without_touching_sync_log(): void
    {
        $t = $this->terminal(['last_seen_at' => now()->subHour(), 'zk_last_pull_at' => null, 'zk_last_status' => null]);
        $before = (int) DB::table('sync_changes')->max('id');
        $this->asDevice()->postJson('/api/v1/sync/terminal-status', ['devices' => [
            ['uuid' => $t->uuid, 'last_seen_at' => now()->toIso8601String(), 'last_pull_at' => now()->subMinutes(2)->toIso8601String(),
                'status' => 'ok', 'error' => null, 'record_count' => 12, 'details' => ['driver' => 'zk', 'pending_queue' => 0, 'kotu anahtar' => 'x']],
            ['uuid' => (string) Str::uuid7(), 'last_seen_at' => now()->toIso8601String()],
        ]])->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('unknown', 1);

        $this->assertEqualsWithDelta(now()->timestamp, strtotime((string) DB::table('devices')->where('id', $t->id)->value('last_seen_at')), 5,
            'sunucudaki otomatik yoklama köprünün canlı olduğunu görür');
        app(Sweeper::class)->sweep(['devices'], true);
        $this->assertSame($before, (int) DB::table('sync_changes')->max('id'), 'rapor değişiklik günlüğüne girmez');

        // Eski rapor yenisini ezmez
        $this->asDevice()->postJson('/api/v1/sync/terminal-status', ['devices' => [
            ['uuid' => $t->uuid, 'last_pull_at' => now()->subHour()->toIso8601String(), 'status' => 'error', 'error' => 'eski'],
        ]])->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin);
        $bridge = $this->getJson('/api/v1/attendance/devices')->assertOk()->json('data.0.bridge');
        $this->assertSame('Ofis Mac', $bridge['via']);
        $this->assertTrue($bridge['connected']);
        $this->assertSame('ok', $bridge['status']);
        $this->assertSame(12, $bridge['record_count']);
        $this->assertSame(['driver' => 'zk', 'pending_queue' => 0], $bridge['details']);
    }

    public function test_local_reporter_sends_status_without_ip_or_cursor(): void
    {
        config(['sync.server_url' => 'https://sunucu.test', 'sync.device_token' => 'cihaz-jetonu']);
        $t = $this->terminal(['zk_last_error' => null, 'zk_last_record_count' => 3]);
        $sent = [];
        Http::fake(function (HttpRequest $r) use (&$sent) {
            $sent[] = $r;

            return Http::response(['accepted' => 1, 'unknown' => 0]);
        });
        $this->assertSame('ok', app(TerminalStatusReporter::class)->report()['status']);
        $this->assertStringEndsWith('/api/v1/sync/terminal-status', $sent[0]->url());
        $this->assertSame($t->uuid, $sent[0]['devices'][0]['uuid']);
        $this->assertSame(3, $sent[0]['devices'][0]['record_count']);
        $this->assertStringNotContainsString('192.168.1.50', $sent[0]->body());
        $this->assertStringNotContainsString('zk_cursor', $sent[0]->body());
        $this->assertSame('skipped', app(TerminalStatusReporter::class)->runSafely()['status'], 'dakikada birden sık değil');
    }

    // ================================================================== bildirimler

    public function test_staff_notifications_go_down_and_portal_ones_do_not(): void
    {
        $student = $this->makeUser('20260001', 'student');
        $before = (int) DB::table('sync_changes')->max('id');
        app(NotificationService::class)->notify([$this->admin->id, $student->id], 'warning', 'Cihaz verisi yok', 'Kontrol edin', '/yoklama/cihazlar');
        app(Sweeper::class)->sweep(['app_notifications']);

        $changes = DB::table('sync_changes')->where('id', '>', $before)->where('table_name', 'app_notifications')->get();
        $this->assertCount(1, $changes, 'yalnız personelin bildirimi');
        $pull = app(PullService::class)->pull($this->device, $this->admin, $before, 100);
        $n = collect($pull['changes'])->firstWhere('table', 'app_notifications');
        $this->assertSame('Cihaz verisi yok', $n['fields']['title']);
        $this->assertSame($this->admin->uuid ?? DB::table('users')->where('id', $this->admin->id)->value('uuid'), $n['fields']['user_id']);

        $page = app(SnapshotService::class)->page($this->device, $this->admin, 'app_notifications', 0, 100);
        $this->assertCount(1, $page['rows']);

        // Mac'e iner, okundu yapılır → kuyruk → sunucuda okundu
        $uuid = $n['row'];
        DB::table('app_notifications')->where('uuid', $uuid)->update(['uuid' => null]);   // "yerel kopya" için yer aç
        app(LocalApplier::class)->apply(['table' => 'app_notifications', 'row' => $uuid, 'op' => 'insert', 'fields' => $n['fields']]);
        $localId = (int) DB::table('app_notifications')->where('uuid', $uuid)->value('id');
        DB::table('app_notifications')->whereNull('uuid')->delete();

        config(['kurs.node' => 'local', 'sync.server_url' => 'https://sunucu.test', 'sync.device_token' => 'cihaz-jetonu']);
        $this->actingAs($this->admin, 'web');
        $this->postJson('/api/v1/notifications/read', ['ids' => [$localId]])->assertOk();
        $this->assertSame([$uuid], array_keys(app(NotificationReadReporter::class)->queue()));

        $sent = [];
        Http::fake(function (HttpRequest $r) use (&$sent) {
            $sent[] = $r;

            return Http::response(['marked' => 1]);
        });
        $this->assertSame(1, app(NotificationReadReporter::class)->report()['sent']);
        $this->assertSame($uuid, $sent[0]['reads'][0]['uuid']);
        $this->assertSame([], app(NotificationReadReporter::class)->queue(), 'gönderilen kuyruktan düşer');

        // Sunucu ucu: okundu işaretler (updated_at ile → süpürücü değişikliği görür)
        config(['kurs.node' => 'server']);
        DB::table('app_notifications')->where('uuid', $uuid)->update(['read_at' => null]);
        $this->asDevice()->postJson('/api/v1/sync/notification-reads', ['reads' => [
            ['uuid' => $uuid, 'read_at' => now()->toIso8601String()], ['uuid' => (string) Str::uuid7()],
        ]])->assertOk()->assertJsonPath('marked', 1);
        $this->assertNotNull(DB::table('app_notifications')->where('uuid', $uuid)->value('read_at'));
    }

    // ================================================================== sırlar

    public function test_secrets_never_reach_the_device(): void
    {
        DB::table('integrations')->insert(['branch_id' => $this->branch->id, 'kind' => 'sms', 'provider' => 'netgsm', 'is_enabled' => true,
            'config_encrypted' => encrypt(['api_key' => 'GIZLI-ANAHTAR']), 'created_at' => now(), 'updated_at' => now()]);
        $this->terminal();
        app(Sweeper::class)->sweep(null, true);

        $manifest = app(SnapshotService::class)->manifest($this->device, $this->admin);
        $tables = array_column($manifest['tables'], 'table');
        $this->assertContains('devices', $tables);
        $this->assertContains('app_notifications', $tables);
        foreach (['integrations', 'webhooks', 'webhook_deliveries', 'calendar_feeds', 'push_tokens', 'sync_terminal_reports', 'personal_access_tokens'] as $t) {
            $this->assertNotContains($t, $tables, "$t anlık görüntüde olmamalı");
            try {
                app(SnapshotService::class)->page($this->device, $this->admin, $t, 0, 10);
                $this->fail("$t sayfası verilmemeli");
            } catch (SyncReject) {
                $this->assertTrue(true);
            }
        }
        $pull = app(PullService::class)->pull($this->device, $this->admin, 0, 5000);
        $blob = json_encode($pull, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('GIZLI-ANAHTAR', $blob);
        $this->assertStringNotContainsString('config_encrypted', $blob);
        $this->assertStringNotContainsString('api_token_hash', $blob);
        $this->assertStringNotContainsString(str_repeat('a', 64), $blob, 'cihaz jeton özeti inmez');
    }

    // ================================================================== sonradan katılan tablolar

    public function test_paired_mac_fetches_late_tables_once_and_pushes_local_only_rows(): void
    {
        config(['kurs.node' => 'local', 'sync.server_url' => 'https://sunucu.test', 'sync.device_token' => 'cihaz-jetonu']);
        $state = app(LocalState::class);
        $state->put('snapshot_done_at', now()->toIso8601String());
        $state->put(LocalSyncEngine::LATE_KEY, null);
        $localOnly = (string) Str::uuid7();
        DB::table('devices')->insert(['uuid' => $localOnly, 'branch_id' => $this->branch->id, 'name' => 'Mac terminali', 'kind' => 'fingerprint',
            'direction' => 'both', 'api_token_hash' => str_repeat('c', 64), 'api_token_prefix' => '', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $serverUuid = (string) Str::uuid7();
        $userUuid = (string) DB::table('users')->where('id', $this->admin->id)->value('uuid');
        $branchUuid = (string) DB::table('branches')->where('id', $this->branch->id)->value('uuid');

        $calls = [];
        Http::fake(function (HttpRequest $r) use (&$calls, $serverUuid, $userUuid, $branchUuid, $localOnly) {
            $calls[] = $r->method().' '.parse_url($r->url(), PHP_URL_PATH);
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/sync/rows')) {
                return Http::response(['rows' => array_map(fn ($u) => ['row' => $u, 'fields' => null], $r['uuids'])]);
            }
            if (str_ends_with($path, '/sync/push')) {
                return Http::response(['results' => array_map(fn ($c) => ['id' => $c['id'], 'status' => 'accepted'], $r['changes']), 'accepted' => count($r['changes'])]);
            }
            if (str_ends_with($path, '/sync/snapshot/devices')) {
                return Http::response(['table' => 'devices', 'next' => null, 'rows' => [
                    ['row' => $serverUuid, 'fields' => ['branch_id' => $branchUuid, 'name' => 'Web terminali', 'kind' => 'fingerprint', 'direction' => 'entry', 'is_active' => 1, 'zk_ip' => '192.168.1.77']],
                ]]);
            }
            if (str_ends_with($path, '/sync/snapshot/app_notifications')) {
                return Http::response(['table' => 'app_notifications', 'next' => null, 'rows' => [
                    ['row' => (string) Str::uuid7(), 'fields' => ['user_id' => $userUuid, 'type' => 'warning', 'title' => 'Sunucudan', 'body' => 'x', 'created_at' => now()->format('Y-m-d H:i:s')]],
                ]]);
            }

            return Http::response(['table' => 'x', 'next' => null, 'rows' => []]);
        });

        $out = app(LocalSyncEngine::class)->lateTables();
        $this->assertContains('devices', $out['tables']);
        $this->assertContains('app_notifications', $out['tables']);
        $this->assertSame(1, $out['orphans'], 'yalnız Mac\'te duran terminal sunucuya gönderildi');
        $this->assertContains('POST /api/v1/sync/push', $calls);
        $this->assertSame('192.168.1.77', DB::table('devices')->where('uuid', $serverUuid)->value('zk_ip'));
        $this->assertSame('Sunucudan', DB::table('app_notifications')->where('user_id', $this->admin->id)->value('title'));

        // İkinci kez çekilmez
        $calls = [];
        $this->assertSame([], app(LocalSyncEngine::class)->lateTables(true)['tables']);
        $this->assertSame([], $calls);
    }

    public function test_web_only_actions_are_refused_on_desktop_instead_of_silently_lost(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/message-templates', [])->assertStatus(422);   // web: normal doğrulama
        config(['kurs.node' => 'local']);
        foreach ([['post', '/api/v1/messages/send'], ['post', '/api/v1/message-templates'], ['post', '/api/v1/automations'],
            ['put', '/api/v1/integrations/whatsapp'], ['post', '/api/v1/webhooks'], ['post', '/api/v1/campaigns'],
            ['post', '/api/v1/message-suppressions']] as [$m, $url]) {
            $this->json($m, $url, [])->assertStatus(409)->assertJsonPath('error_code', 'web_only');
        }
        $this->getJson('/api/v1/message-templates')->assertOk();   // okuma açık
    }
}
