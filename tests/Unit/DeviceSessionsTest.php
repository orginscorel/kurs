<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Permissions;
use App\Sync\ChangeRecorder;
use App\Sync\Local\LocalSessionDirectory;
use App\Sync\Local\LocalSessionStore;
use App\Sync\Local\SessionReporter;
use App\Sync\Models\SyncDevice;
use App\Sync\SyncSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Açık oturumlar ve cihazlar: masaüstü (Mac) uygulaması oturum raporu → tek liste, uzaktan kapatma isteği →
 * cihaz yanıtında dönme → onay, yetki (başkasının oturumunu kapatamama), ham oturum kimliğinin sızmaması.
 * Bellek içi SQLite; canlı veritabanına dokunmaz.
 */
class DeviceSessionsTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private User $ayse;

    private User $mehmet;

    private SyncDevice $device;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['kurs.silent_events' => true, 'kurs.node' => 'server']);
        $this->artisan('migrate', ['--force' => true])->run();
        app(SyncSchema::class)->flush();
        app(ChangeRecorder::class)->reset();
        \App\Sync\Server\DeviceSessionService::flushReady();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);
        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        Role::findOrCreate('danisman', 'web')->syncPermissions(['sync.use']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = $this->makeUser('mudur1', 'yonetici');
        $this->ayse = $this->makeUser('ayse', 'danisman');
        $this->mehmet = $this->makeUser('mehmet', 'danisman');
        app(SyncSchema::class)->backfillUuids();

        $token = $this->admin->createToken('sync:test', ['sync']);
        $this->deviceToken = $token->plainTextToken;
        $this->device = SyncDevice::query()->create([
            'uuid' => (string) Str::uuid7(), 'branch_id' => $this->branch->id, 'user_id' => $this->admin->id,
            'code' => 'D1', 'name' => 'Ofis Mac', 'platform' => 'macos', 'app_version' => '1.12.1', 'token_id' => $token->accessToken->id,
            'status' => 'active', 'paired_at' => now(), 'last_seen_at' => now(), 'last_ip' => '85.1.2.3',
        ]);
    }

    private function makeUser(string $username, string $role, ?int $branchId = null): User
    {
        $u = User::query()->create([
            'branch_id' => $branchId ?? $this->branch->id, 'name' => ucfirst($username), 'username' => $username,
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function uuid(User $u): string
    {
        return (string) DB::table('users')->where('id', $u->id)->value('uuid');
    }

    private function asDevice()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->deviceToken);
    }

    private function asUser(User $u)
    {
        $this->app['auth']->forgetGuards();
        $this->withoutToken();

        return $this->actingAs($u, 'web');
    }

    private function report(array $sessions, array $acks = [])
    {
        return $this->asDevice()->postJson('/api/v1/sync/sessions', ['sessions' => $sessions, 'acks' => $acks]);
    }

    public function test_report_appears_in_single_list_and_remote_close_roundtrip(): void
    {
        $raw = Str::random(40);
        $hash = hash('sha256', $raw);
        $this->report([['user' => $this->uuid($this->ayse), 'hash' => $hash, 'last_activity' => time() - 30]])
            ->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('revocations', []);

        // Web oturumu da aynı listede
        DB::table('sessions')->insert(['id' => 'webraw'.Str::random(34), 'user_id' => $this->ayse->id, 'ip_address' => '10.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120', 'payload' => '', 'last_activity' => time()]);

        $res = $this->asUser($this->ayse)->getJson('/api/v1/auth/sessions')->assertOk();
        $rows = collect($res->json('data'));
        $app = $rows->firstWhere('id', 'app:'.$hash);
        $this->assertNotNull($app, 'uygulama oturumu listede');
        $this->assertSame('app', $app['kind']);
        $this->assertSame('Mac uygulaması', $app['kind_label']);
        $this->assertSame('Ofis Mac', $app['device']);
        $this->assertSame('85.1.2.3', $app['ip_address']);
        $this->assertSame('active', $app['status']);
        $this->assertEqualsWithDelta(time() - 30, strtotime($app['last_active_at']), 5, 'son etkinlik saat dilimi kaymadan saklanır');
        $this->assertNotNull($rows->firstWhere('kind', 'web'));
        // Eşitleme cihaz jetonları "mobil oturum" olarak listelenmez
        $this->assertNull($rows->first(fn ($r) => str_contains((string) $r['device'], 'sync:')));
        $this->assertStringNotContainsString($raw, $res->getContent());

        // Uzaktan kapat → "kapatılıyor"
        $this->asUser($this->ayse)->deleteJson('/api/v1/auth/sessions/'.rawurlencode('app:'.$hash))->assertOk();
        $this->assertSame(1, DB::table('sync_session_revocations')->where('status', 'pending')->count());
        $this->asUser($this->ayse)->deleteJson('/api/v1/auth/sessions/'.rawurlencode('app:'.$hash))->assertOk();   // idempotent
        $this->assertSame(1, DB::table('sync_session_revocations')->count());
        $this->assertSame('closing', collect($this->asUser($this->ayse)->getJson('/api/v1/auth/sessions')->json('data'))->firstWhere('id', 'app:'.$hash)['status']);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.session_revoked')->where('description', 'like', '%Ofis Mac%')->exists());

        // Cihaz bir sonraki raporda isteği alır
        $res = $this->report([['user' => $this->uuid($this->ayse), 'hash' => $hash, 'last_activity' => time()]])->assertOk();
        $rev = $res->json('revocations');
        $this->assertCount(1, $rev);
        $this->assertSame($hash, $rev[0]['hash']);
        $this->assertSame($this->uuid($this->ayse), $rev[0]['user']);

        // Uyguladı, onayladı; oturum artık raporda yok → listeden düşer, istek kapanır
        $this->report([], [$rev[0]['id']])->assertOk()->assertJsonPath('revocations', []);
        $this->assertSame('done', DB::table('sync_session_revocations')->value('status'));
        $this->assertNull(collect($this->asUser($this->ayse)->getJson('/api/v1/auth/sessions')->json('data'))->firstWhere('id', 'app:'.$hash));
    }

    public function test_users_cannot_close_others_sessions_and_device_cannot_proxy_foreign_users(): void
    {
        $hash = hash('sha256', Str::random(40));
        $this->report([['user' => $this->uuid($this->ayse), 'hash' => $hash, 'last_activity' => time()]])->assertOk();

        // Mehmet, Ayşe'nin oturumunu kapatamaz
        $this->asUser($this->mehmet)->deleteJson('/api/v1/auth/sessions/'.rawurlencode('app:'.$hash))->assertNotFound();
        $webId = 'webraw'.Str::random(34);
        DB::table('sessions')->insert(['id' => $webId, 'user_id' => $this->ayse->id, 'ip_address' => null, 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        $this->asUser($this->mehmet)->deleteJson('/api/v1/auth/sessions/'.rawurlencode('web:'.hash('sha256', $webId)))->assertNotFound();
        $this->asUser($this->mehmet)->deleteJson('/api/v1/auth/sessions')->assertOk();
        $this->assertSame(0, DB::table('sync_session_revocations')->count());
        $this->assertTrue(DB::table('sessions')->where('id', $webId)->exists());

        // Cihaz, raporlamadığı (oturumu olmayan) kullanıcının listesini çekemez / kapatamaz
        $this->asDevice()->getJson('/api/v1/sync/user-sessions?'.http_build_query(['user' => $this->uuid($this->mehmet), 'session' => $hash]))
            ->assertForbidden();
        $this->asDevice()->postJson('/api/v1/sync/user-sessions/revoke', ['user' => $this->uuid($this->mehmet), 'session' => $hash, 'id' => 'web:'.hash('sha256', $webId)])
            ->assertForbidden();
        // Raporladığı kullanıcı kendi listesini görür (mevcut oturum işaretli) ve kendi web oturumunu kapatır
        $list = $this->asDevice()->getJson('/api/v1/sync/user-sessions?'.http_build_query(['user' => $this->uuid($this->ayse), 'session' => $hash]))->assertOk();
        $this->assertTrue(collect($list->json('data'))->firstWhere('id', 'app:'.$hash)['is_current']);
        $this->asDevice()->postJson('/api/v1/sync/user-sessions/revoke', ['user' => $this->uuid($this->ayse), 'session' => $hash, 'id' => 'app:'.$hash])
            ->assertStatus(422);   // kullandığı oturum
        $this->asDevice()->postJson('/api/v1/sync/user-sessions/revoke', ['user' => $this->uuid($this->ayse), 'session' => $hash, 'id' => 'web:'.hash('sha256', $webId)])
            ->assertOk();
        $this->assertFalse(DB::table('sessions')->where('id', $webId)->exists());
        $this->assertTrue(AuditLog::query()->where('user_id', $this->ayse->id)->where('description', 'like', '%Ofis Mac%uygulamasından%')->exists(),
            'denetim kaydında işlemi yapan cihazı eşleştiren değil oturum sahibidir');

        // Başka şubenin kullanıcısı raporlanamaz
        $other = Branch::query()->create(['code' => 'NIKSAR', 'name' => 'Niksar']);
        $foreign = $this->makeUser('yabanci', 'danisman', $other->id);
        app(SyncSchema::class)->backfillUuids();
        $this->report([['user' => $this->uuid($foreign), 'hash' => hash('sha256', 'x'), 'last_activity' => time()]])->assertJsonPath('accepted', 0);

        // Normal (tam yetkili) jeton cihaz ucuna giremez
        $plain = $this->ayse->createToken('mobil', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/sync/sessions', ['sessions' => []])->assertStatus(403);
    }

    public function test_admin_sees_and_closes_user_sessions_and_devices_list_counts(): void
    {
        $hash = hash('sha256', Str::random(40));
        $this->report([['user' => $this->uuid($this->ayse), 'hash' => $hash, 'last_activity' => time()]])->assertOk();

        $detail = $this->asUser($this->admin)->getJson('/api/v1/admin-users/'.$this->ayse->id)->assertOk();
        $this->assertSame('app:'.$hash, $detail->json('sessions.0.id'));

        $devices = $this->asUser($this->admin)->getJson('/api/v1/sync/devices')->assertOk();
        $this->assertSame('Ayse', $devices->json('data.0.open_sessions.0.name'));

        $this->asUser($this->admin)->deleteJson('/api/v1/admin-users/'.$this->ayse->id.'/sessions/'.rawurlencode('app:'.$hash))->assertOk();
        $this->assertTrue(AuditLog::query()->where('description', 'like', '%Ayse kullanıcısının%Ofis Mac%')->exists());
        $this->assertTrue((bool) $this->asUser($this->admin)->getJson('/api/v1/sync/devices')->json('data.0.open_sessions.0.closing'));

        // Yetkisiz kullanıcı yönetici ucuna giremez
        $this->asUser($this->mehmet)->deleteJson('/api/v1/admin-users/'.$this->ayse->id.'/sessions')->assertForbidden();
    }

    public function test_local_node_reports_hashes_only_and_applies_remote_close(): void
    {
        $dir = storage_path('framework/testing/sessions-'.Str::random(6));
        @mkdir($dir, 0777, true);
        config(['session.driver' => 'file', 'session.files' => $dir, 'session.serialization' => 'json', 'session.encrypt' => false,
            'sync.server_url' => 'https://sunucu.test', 'sync.device_token' => 'cihaz-jetonu']);
        $key = Auth::guard('web')->getName();
        $raw = Str::random(40);
        file_put_contents($dir.'/'.$raw, json_encode(['_token' => 'x', $key => $this->ayse->id]));
        file_put_contents($dir.'/'.Str::random(40), json_encode(['_token' => 'y']));   // oturumsuz (giriş yok): raporlanmaz
        $hash = hash('sha256', $raw);

        $calls = [];
        Http::fake(function (HttpRequest $r) use (&$calls, $hash) {
            $calls[] = $r;
            $first = count($calls) === 1;

            return Http::response(['accepted' => 1, 'device' => ['name' => 'Ofis Mac'],
                'revocations' => $first ? [['id' => 7, 'user' => $this->uuid($this->ayse), 'hash' => $hash]] : []]);
        });

        $store = app(LocalSessionStore::class);
        $this->assertSame([$hash], array_column($store->all(), 'hash'));

        $res = app(SessionReporter::class)->report();
        $this->assertSame(1, $res['applied']);
        $this->assertCount(2, $calls, 'uygulanan kapatma hemen onaylanır');
        foreach ($calls as $c) {
            $this->assertStringNotContainsString($raw, $c->body(), 'ham oturum kimliği sunucuya gitmez');
        }
        $this->assertSame($hash, $calls[0]['sessions'][0]['hash']);
        $this->assertSame([7], $calls[1]['acks']);
        $this->assertSame([], $calls[1]['sessions'], 'onay raporunda kapatılan oturum yok');
        $this->assertFileDoesNotExist($dir.'/'.$raw);
        $this->assertNotNull(DB::table('users')->where('id', $this->ayse->id)->value('remember_token'), 'beni-hatırla jetonu yenilendi');

        // Dakikada birden sık rapor gönderilmez
        $this->assertSame('skipped', app(SessionReporter::class)->runSafely()['status']);
        array_map('unlink', glob($dir.'/*'));
        @rmdir($dir);
    }

    public function test_local_list_offline_shows_only_this_mac_and_remote_close_needs_connection(): void
    {
        $dir = storage_path('framework/testing/sessions-'.Str::random(6));
        @mkdir($dir, 0777, true);
        config(['session.driver' => 'file', 'session.files' => $dir, 'session.serialization' => 'json', 'session.encrypt' => false,
            'sync.server_url' => 'https://sunucu.test', 'sync.device_token' => 'cihaz-jetonu', 'kurs.node' => 'local']);
        $key = Auth::guard('web')->getName();
        $mine = Str::random(40);
        $other = Str::random(40);
        file_put_contents($dir.'/'.$mine, json_encode([$key => $this->ayse->id]));
        file_put_contents($dir.'/'.$other, json_encode([$key => $this->ayse->id]));
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $request = Request::create('/api/v1/auth/sessions');
        $session = app('session')->driver();
        $session->setId($mine);
        $request->setLaravelSession($session);
        $this->actingAs($this->ayse, 'web');

        $dirSvc = app(LocalSessionDirectory::class);
        $res = $dirSvc->list($this->ayse, $request);
        $this->assertSame('local', $res['scope']);
        $this->assertStringContainsString('tüm cihazlar', $res['notice']);
        $this->assertCount(2, $res['data']);
        $this->assertTrue($res['data'][0]['is_current'] || $res['data'][1]['is_current']);
        $this->assertStringNotContainsString($mine, json_encode($res));

        // Bu Mac'teki diğer oturum çevrimdışı da kapanır
        $this->assertSame('Oturum kapatıldı.', $dirSvc->revoke($this->ayse, 'app:'.hash('sha256', $other), $request));
        $this->assertFileDoesNotExist($dir.'/'.$other);
        $this->assertFileExists($dir.'/'.$mine);

        // Başka cihaz/tarayıcı oturumu çevrimdışıyken kapatılamaz (açık uyarı)
        try {
            $dirSvc->revoke($this->ayse, 'web:'.hash('sha256', 'baska'), $request);
            $this->fail('çevrimdışı uzaktan kapatma reddedilmeli');
        } catch (BusinessRuleException $e) {
            $this->assertSame('offline_not_supported', $e->errorCode);
        }
        array_map('unlink', glob($dir.'/*'));
        @rmdir($dir);
    }
}
