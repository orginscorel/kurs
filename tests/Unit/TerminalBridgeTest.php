<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Services\Devices\Drivers\DriverRegistry;
use App\Services\Devices\Drivers\Yt33\Yt33Driver;
use App\Services\Devices\Drivers\ZkTecoDriver;
use App\Services\Devices\Network\RawTcpDiagnostic;
use App\Services\Devices\Network\SocketFailure;
use App\Services\Devices\Network\TcpProbe;
use App\Services\Devices\Terminal\DriverStatus;
use App\Services\Devices\Terminal\PushListener;
use App\Services\Devices\Terminal\PushListenerException;
use App\Services\Devices\Terminal\TerminalStateStore;
use App\Services\Devices\Terminal\TerminalConnectionTester;
use App\Services\Devices\Terminal\TerminalEndpoint;
use App\Support\BranchContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\NullHandler;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TERMİNAL KÖPRÜSÜ (sürücü bağımsız): iki aşamalı test, errno sınıflandırması, sürücüler,
 * ham TCP tanılaması ve API. Gerçek soketlerle (127.0.0.1) çalışır; dış ağa çıkmaz.
 */
class TerminalBridgeTest extends TestCase
{
    /** @var list<resource> */
    private array $servers = [];

    private string $stateDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        $this->stateDir = sys_get_temp_dir().'/kurs-terminal-test-'.bin2hex(random_bytes(4));
        config([
            'kurs.silent_events' => true,
            'kurs.data_key' => 'base64:'.base64_encode(random_bytes(32)),
            'devices_zk.state_path' => $this->stateDir.'/state.json',
            'logging.channels.terminal' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        ]);
        TcpProbe::$osFamily = 'Linux';
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            @fclose($server);
        }
        TcpProbe::$osFamily = null;
        @array_map('unlink', glob($this->stateDir.'/*') ?: []);
        @rmdir($this->stateDir);

        parent::tearDown();
    }

    /** Dinleyen ama HİÇ yanıt vermeyen sahte cihaz (bağlantılar çekirdek kuyruğunda bekler). */
    private function silentServer(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $this->servers[] = $server;
        [, $port] = TcpProbe::split((string) stream_socket_get_name($server, false));

        return [$server, $port];
    }

    private function closedPort(): int
    {
        [$server, $port] = $this->silentServer();
        fclose($server);
        array_pop($this->servers);

        return $port;
    }

    private function endpoint(int $port, ?int $deviceId = null): TerminalEndpoint
    {
        return new TerminalEndpoint('127.0.0.1', $port, 'tcp', null, 2.0, 1.0, $deviceId);
    }

    // ============================================================ AŞAMA 1: soket

    public function test_open_port_socket_probe_passes_with_timing_and_local_ip(): void
    {
        [, $port] = $this->silentServer();

        $result = app(TcpProbe::class)->probe('127.0.0.1', $port, 2.0);

        $this->assertTrue($result->connected);
        $this->assertSame('127.0.0.1', $result->localIp);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
        $this->assertNull($result->failure);
    }

    public function test_closed_port_is_reported_as_refused_not_as_generic_failure(): void
    {
        $result = app(TcpProbe::class)->probe('127.0.0.1', $this->closedPort(), 2.0);

        $this->assertFalse($result->connected);
        $this->assertSame(SocketFailure::Refused, $result->failure);
        $this->assertStringContainsString('reddedildi', $result->message);
        $this->assertStringNotContainsString('4370', $result->hint);
    }

    public function test_errno_classification_and_macos_local_network_hint(): void
    {
        $this->assertSame(SocketFailure::HostUnreachable, SocketFailure::classify(65, 'No route to host'));
        $this->assertSame(SocketFailure::HostUnreachable, SocketFailure::classify(113, ''));
        $this->assertSame(SocketFailure::Refused, SocketFailure::classify(61, ''));
        $this->assertSame(SocketFailure::Timeout, SocketFailure::classify(0, 'Operation timed out'));
        $this->assertSame(SocketFailure::NetworkUnreachable, SocketFailure::classify(51, ''));

        $mac = SocketFailure::HostUnreachable->hint('192.168.68.60', 5005, true);
        $this->assertStringContainsString('Yerel Ağ', $mac);
        $this->assertStringNotContainsString('Yerel Ağ', SocketFailure::HostUnreachable->hint('192.168.68.60', 5005, false));
        // Reddedilen bağlantı ağın çalıştığını gösterir → macOS izni önerilmez
        $this->assertStringNotContainsString('Yerel Ağ', SocketFailure::Refused->hint('192.168.68.60', 5005, true));
        $this->assertTrue(SocketFailure::Timeout->mayBeLocalNetworkPermission(true));
    }

    // ============================================================ AŞAMA 2: protokol

    public function test_socket_open_but_silent_device_is_network_pass_protocol_fail_never_unreachable(): void
    {
        [, $port] = $this->silentServer();

        $report = app(TerminalConnectionTester::class)->run(app(ZkTecoDriver::class), $this->endpoint($port))->toArray();

        $this->assertSame('kismi', $report['durum']);
        $this->assertSame('basarili', $report['asamalar']['ag']['durum']);
        $this->assertSame('basarili', $report['asamalar']['tcp']['durum']);
        $this->assertSame('basarisiz', $report['asamalar']['protokol']['durum']);
        $this->assertStringStartsWith('Ağ bağlantısı başarılı. TCP ', $report['mesaj']);
        $this->assertStringContainsString('Bu durum ağ arızası anlamına gelmez', $report['mesaj']);
        $this->assertStringNotContainsString('bağlanılamadı', $report['mesaj']);
        $this->assertStringNotContainsString('ulaşılamıyor', $report['mesaj']);
        $this->assertSame('127.0.0.1', $report['kopru_ip']);
        // Tek özet cümle; ZK'ya özgü "biri parmak okutuyor" metni özetle birlikte tekrar edilmez, yanlış sürücü önerilir
        $this->assertStringNotContainsString('parmak okutuyor', $report['mesaj'].$report['oneri']);
        $this->assertStringContainsString('Perkotek YT33', $report['oneri']);
    }

    public function test_perkotek_driver_reports_protocol_not_verified_and_sends_no_bytes(): void
    {
        [$server, $port] = $this->silentServer();

        $report = app(TerminalConnectionTester::class)->run(app(Yt33Driver::class), $this->endpoint($port))->toArray();

        $this->assertSame('kismi', $report['durum']);
        $this->assertSame('protokol_dogrulanmadi', $report['kod']);
        $this->assertSame('basarili', $report['asamalar']['tcp']['durum']);
        $this->assertSame('dogrulama_bekliyor', $report['asamalar']['protokol']['durum']);
        $this->assertSame('bekliyor', $report['asamalar']['kimlik']['durum']);
        $this->assertStringContainsString('Protokol sürücüsü henüz doğrulanmadı', $report['asamalar']['protokol']['ayrinti']);
        $this->assertSame(
            "Ağ bağlantısı başarılı. TCP {$port} portuna bağlantı kurulabiliyor ancak YT33 uygulama protokolü henüz doğrulanmadı. Bu durum ağ arızası anlamına gelmez.",
            $report['mesaj'],
        );
        $this->assertSame("Network: Başarılı · TCP {$port}: Başarılı · Protocol: Doğrulama bekliyor · Device identification: Bekliyor", $report['teknik']);
        $this->assertStringStartsWith("Cihaza ağ üzerinden erişiliyor ve TCP {$port} portu açık.", $report['asamalar']['ag']['ayrinti']);
        $this->assertStringNotContainsString('Başarısız', $report['teknik']);

        // Sürücü cihaza TEK BAYT göndermemeli (ZK paketi ya da uydurma başlık yok)
        $conn = stream_socket_accept($server, 1);
        $this->assertNotFalse($conn);
        stream_set_blocking($conn, false);
        usleep(50000);
        $this->assertSame('', (string) fread($conn, 1024));
        fclose($conn);
    }

    public function test_unreachable_socket_is_the_only_case_that_says_could_not_connect(): void
    {
        $report = app(TerminalConnectionTester::class)->run(app(Yt33Driver::class), $this->endpoint($this->closedPort()))->toArray();

        $this->assertSame('hata', $report['durum']);
        $this->assertSame('baglanti', $report['kod']);
        $this->assertStringStartsWith('Cihaza bağlanılamadı', $report['mesaj']);
        $this->assertSame('basarili', $report['asamalar']['ag']['durum'], 'Reddedilen port = IP\'ye ulaşıldı');
        $this->assertSame('basarisiz', $report['asamalar']['tcp']['durum']);
        $this->assertSame('denenmedi', $report['asamalar']['protokol']['durum']);
    }

    public function test_unverified_driver_methods_return_typed_results_not_fake_data(): void
    {
        $perkotek = app(Yt33Driver::class);
        $endpoint = $this->endpoint(1);

        foreach ([$perkotek->identifyDevice($endpoint), $perkotek->fetchUsers($endpoint, 1), $perkotek->fetchAttendanceLogs($endpoint, 1), $perkotek->parsePush('x', 1)] as $result) {
            $this->assertSame(DriverStatus::ProtocolNotImplemented, $result->status);
            $this->assertNull($result->data);
        }

        $registry = app(DriverRegistry::class);
        $this->assertInstanceOf(Yt33Driver::class, $registry->terminal('perkotek_fk'));
        $this->assertSame(DriverStatus::Unsupported, $registry->terminal('generic_tcp')->fetchUsers($endpoint, 1)->status);
        $this->assertNull($registry->terminal('adms'));
    }

    // ============================================================ ham TCP tanılaması

    public function test_raw_diagnostic_listens_only_by_default_and_sends_exact_user_hex(): void
    {
        [$server, $port] = $this->silentServer();

        $listen = app(RawTcpDiagnostic::class)->run('127.0.0.1', $port, null, 0.5);
        $this->assertSame('ok', $listen['durum']);
        $this->assertSame(0, $listen['gonderilen_bayt']);
        $this->assertSame(0, $listen['alinan_bayt']);
        $this->assertSame('dinleme_suresi_doldu', $listen['kapanis']);

        $sent = app(RawTcpDiagnostic::class)->run('127.0.0.1', $port, '50 00 0a ff', 0.5);
        $this->assertSame(4, $sent['gonderilen_bayt']);

        $first = stream_socket_accept($server, 1);   // yalnız dinleyen bağlantı: hiçbir şey gelmemeli
        stream_set_blocking($first, false);
        $second = stream_socket_accept($server, 1);
        stream_set_blocking($second, true);
        stream_set_timeout($second, 1);
        $this->assertSame('', (string) fread($first, 16));
        $this->assertSame("\x50\x00\x0a\xff", fread($second, 16));
    }

    public function test_raw_diagnostic_records_device_greeting_as_hex_and_ascii(): void
    {
        $script = '$s=stream_socket_server("tcp://127.0.0.1:0");echo explode(":",stream_socket_get_name($s,false))[1],"\n";'
            .'$c=stream_socket_accept($s,5);fwrite($c,"HELLO\x00\x01\xfe");usleep(300000);fclose($c);';
        $proc = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
        $port = (int) trim((string) fgets($pipes[1]));

        $result = app(RawTcpDiagnostic::class)->run('127.0.0.1', $port, null, 3.0);
        proc_close($proc);

        $this->assertSame(8, $result['alinan_bayt']);
        $this->assertSame('HELLO...', $result['ascii']);
        $this->assertStringContainsString('48 45 4c 4c 4f 00 01 fe', $result['hex']);
        $this->assertSame('cihaz_kapatti', $result['kapanis']);
    }

    public function test_invalid_hex_is_rejected(): void
    {
        $this->assertFalse(RawTcpDiagnostic::parseHex('zz'));
        $this->assertFalse(RawTcpDiagnostic::parseHex('abc'));
        $this->assertSame('', RawTcpDiagnostic::parseHex('  '));
    }

    // ============================================================ API

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
            'api_token_hash' => str_repeat('c', 64), 'api_token_prefix' => 'dev_api00002', 'is_active' => true,
        ]);
    }

    public function test_settings_save_driver_and_never_return_or_accept_web_password_as_comm_key(): void
    {
        $device = $this->actingAdminOnLocalNode();

        $this->postJson("/api/v1/attendance/terminal/cihazlar/{$device->id}/ayar", [
            'surucu' => 'perkotek_fk', 'ip' => '192.168.68.60', 'port' => 5005, 'comm_key' => 'admin',
        ])->assertStatus(422)->assertJsonPath('errors.comm_key.0', 'İletişim şifresi yalnız rakamlardan oluşur (cihazın web arayüzü şifresi değildir).');

        $response = $this->postJson("/api/v1/attendance/terminal/cihazlar/{$device->id}/ayar", [
            'surucu' => 'perkotek_fk', 'ip' => '192.168.68.60', 'port' => 5005, 'comm_key' => '0', 'marka' => 'Perkotek', 'model' => 'YT33',
        ])->assertOk();

        $response->assertJsonPath('cihaz.surucu', 'perkotek_fk')->assertJsonPath('cihaz.port', 5005)
            ->assertJsonPath('cihaz.sifre_tanimli', true)->assertJsonPath('cihaz.model', 'YT33');
        $this->assertStringNotContainsString('"0"', json_encode($response->json('cihaz')) ?: '');
        $this->assertSame('perkotek_fk', $device->fresh()->protocol);

        // ZK uçları Perkotek cihaza ZK paketi göndermez
        $this->getJson("/api/v1/attendance/zk/cihazlar/{$device->id}/kullanicilar")->assertStatus(422)
            ->assertJsonPath('error_code', 'terminal_driver_mismatch');
    }

    public function test_terminal_write_endpoints_are_desktop_only_on_server(): void
    {
        $device = $this->actingAdminOnLocalNode();
        config(['kurs.node' => 'server']);

        $this->postJson("/api/v1/attendance/terminal/cihazlar/{$device->id}/test")->assertStatus(409)->assertJsonPath('error_code', 'terminal_desktop_only');
        $this->postJson('/api/v1/attendance/terminal/ham-tani', ['ip' => '192.168.68.60', 'port' => 5005])->assertStatus(409);
        $this->getJson("/api/v1/attendance/terminal/cihazlar/{$device->id}")->assertOk();   // salt okunur
    }

    public function test_device_test_endpoint_runs_two_stages_and_diagnostics_lists_raw_runs(): void
    {
        $device = $this->actingAdminOnLocalNode();
        [, $port] = $this->silentServer();
        $device->forceFill(['protocol' => 'perkotek_fk', 'zk_ip' => '127.0.0.1', 'zk_port' => $port])->save();

        $this->postJson("/api/v1/attendance/terminal/cihazlar/{$device->id}/test")->assertOk()
            ->assertJsonPath('durum', 'kismi')->assertJsonPath('asamalar.tcp.durum', 'basarili');

        $raw = $this->postJson('/api/v1/attendance/terminal/ham-tani', ['ip' => '127.0.0.1', 'port' => $port, 'dinleme_sn' => 0.5, 'cihaz_id' => $device->id])->assertOk();

        $diag = $this->getJson('/api/v1/attendance/terminal/teshis')->assertOk();
        $diag->assertJsonPath('cihazlar.0.durum.tcp', 'basarili')->assertJsonPath('cihazlar.0.durum.kopru_ip', '127.0.0.1')
            ->assertJsonPath('ham_tanilamalar.0.id', $raw->json('id'));

        $this->get('/api/v1/attendance/terminal/ham-tani/'.$raw->json('id').'/indir')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    // ============================================================ PUSH dinleyicisi

    private function listener(?string $upHost = null, ?int $upPort = null): PushListener
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $listener = app(PushListener::class);
        $listener->open(0, '127.0.0.1');
        $listener->setUpstream($upHost, $upPort);

        return $listener;
    }

    /** $until true dönene dek dinleyiciyi çalıştırır (en çok ~3 sn). */
    private function pump(PushListener $listener, callable $until, float $max = 3.0): void
    {
        $deadline = microtime(true) + $max;
        while (microtime(true) < $deadline) {
            $listener->tick(0.05);
            if ($until()) {
                return;
            }
        }
    }

    private function readAll($stream, PushListener $listener, float $max = 3.0): string
    {
        stream_set_blocking($stream, false);
        $got = '';
        $this->pump($listener, function () use ($stream, &$got) {
            $chunk = (string) @fread($stream, 8192);
            $got .= $chunk;

            return feof($stream);
        }, $max);

        return $got;
    }

    private function packets(): array
    {
        return DB::table('terminal_raw_packets')->orderBy('id')->get()->all();
    }

    public function test_push_listener_stores_http_request_and_replies_empty_200(): void
    {
        $listener = $this->listener();
        $client = stream_socket_client('tcp://127.0.0.1:'.$listener->port(), $e, $s, 2);
        fwrite($client, "POST /iclock/cdata?SN=ABC HTTP/1.1\r\nHost: x\r\nContent-Length: 11\r\n\r\n1\t2026-09-19");

        $response = $this->readAll($client, $listener);
        $listener->close();

        $this->assertSame("HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", $response);
        $rows = $this->packets();
        $this->assertCount(1, $rows);
        $this->assertSame('http', $rows[0]->format);
        $this->assertSame('POST', $rows[0]->http_method);
        $this->assertSame('/iclock/cdata?SN=ABC', $rows[0]->http_path);
        $this->assertSame("1\t2026-09-19", base64_decode($rows[0]->http_body_base64));
        $this->assertSame('device_to_bridge', $rows[0]->direction);
        $this->assertSame('127.0.0.1', $rows[0]->remote_ip);

        $detail = app(\App\Services\Devices\Terminal\TerminalPacketStore::class)->detail((int) $rows[0]->id);
        $this->assertStringContainsString('50 4f 53 54', $detail['hex']);
        $this->assertStringStartsWith('POST /iclock', $detail['ascii']);
    }

    public function test_push_listener_keeps_raw_binary_without_replying_and_marks_duplicates(): void
    {
        $listener = $this->listener();

        foreach ([1, 2] as $_) {
            $client = stream_socket_client('tcp://127.0.0.1:'.$listener->port(), $e, $s, 2);
            fwrite($client, "\xA5\x5A\x01\x00\xFF");
            usleep(50000);
            stream_socket_shutdown($client, STREAM_SHUT_WR);
            $this->assertSame('', $this->readAll($client, $listener), 'Ham TCP\'ye hiçbir şey gönderilmez');
            fclose($client);
        }
        $this->pump($listener, fn () => $listener->activeConnections() === 0);
        $listener->close();

        $rows = $this->packets();
        $this->assertCount(2, $rows);
        $this->assertSame('binary', $rows[0]->format);
        $this->assertSame(hash('sha256', "\xA5\x5A\x01\x00\xFF"), $rows[0]->sha256);
        $this->assertNull($rows[0]->duplicate_of);
        $this->assertSame($rows[0]->id, $rows[1]->duplicate_of);
        $this->assertSame('unparsed', $rows[1]->parse_status);
    }

    public function test_relay_mode_forwards_both_directions_and_records_each_separately(): void
    {
        [$upstream, $upPort] = $this->silentServer();
        stream_set_blocking($upstream, false);
        $listener = $this->listener('127.0.0.1', $upPort);

        $client = stream_socket_client('tcp://127.0.0.1:'.$listener->port(), $e, $s, 2);
        stream_set_blocking($client, false);
        fwrite($client, 'PING-FROM-DEVICE');

        $server = null;
        $this->pump($listener, function () use ($upstream, &$server) {
            $server ??= @stream_socket_accept($upstream, 0) ?: null;

            return $server !== null;
        });
        $this->assertNotNull($server, 'Dinleyici yukarı akışa bağlanmalı');
        stream_set_blocking($server, false);

        $seen = '';
        $this->pump($listener, function () use ($server, &$seen) {
            $seen .= (string) fread($server, 1024);

            return $seen === 'PING-FROM-DEVICE';
        });
        $this->assertSame('PING-FROM-DEVICE', $seen);

        fwrite($server, 'PONG-FROM-PDKS');
        $back = '';
        $this->pump($listener, function () use ($client, &$back) {
            $back .= (string) fread($client, 1024);

            return $back === 'PONG-FROM-PDKS';
        });
        $this->assertSame('PONG-FROM-PDKS', $back, 'Cihaza yanıt yukarı akıştan gelir, kendi 200\'ümüz değil');

        fclose($server);
        fclose($client);
        $this->pump($listener, fn () => $listener->activeConnections() === 0);
        $listener->close();

        $rows = collect($this->packets())->keyBy('direction');
        $this->assertSame('PING-FROM-DEVICE', base64_decode($rows['device_to_upstream']->payload_base64));
        $this->assertSame('PONG-FROM-PDKS', base64_decode($rows['upstream_to_device']->payload_base64));
        $this->assertSame('127.0.0.1:'.$upPort, $rows['device_to_upstream']->upstream);
        $this->assertSame('connected', $rows['upstream_to_device']->upstream_status);
        $this->assertSame($rows['device_to_upstream']->connection_id, $rows['upstream_to_device']->connection_id);
    }

    public function test_relay_failure_falls_back_to_empty_200_and_reports_why(): void
    {
        $listener = $this->listener('127.0.0.1', $this->closedPort());
        $client = stream_socket_client('tcp://127.0.0.1:'.$listener->port(), $e, $s, 2);
        fwrite($client, "POST /push HTTP/1.1\r\nContent-Length: 0\r\n\r\n");

        $response = $this->readAll($client, $listener);
        $listener->close();

        $this->assertStringStartsWith('HTTP/1.1 200 OK', $response);
        $this->assertStringContainsString('Aktarılamadı: 127.0.0.1:', (string) $listener->lastRelayError);
        $this->assertStringContainsString('reddedildi', (string) $listener->lastRelayError);
        $row = $this->packets()[0];
        $this->assertSame('refused', $row->upstream_status);
        $this->assertSame('device_to_bridge', $row->direction);
    }

    public function test_port_in_use_gives_turkish_bind_error(): void
    {
        [, $port] = $this->silentServer();

        try {
            app(PushListener::class)->open($port, '127.0.0.1');
            $this->fail('Kullanımdaki port açılmamalıydı.');
        } catch (PushListenerException $e) {
            $this->assertStringContainsString("{$port} portu başka bir program tarafından kullanılıyor", $e->getMessage());
        }
    }

    public function test_listen_command_respects_settings_and_node(): void
    {
        $this->artisan('migrate', ['--force' => true])->run();

        config(['kurs.node' => 'server']);
        $this->artisan('kurs:terminal-dinle')->assertExitCode(1);

        config(['kurs.node' => 'local']);
        $this->artisan('kurs:terminal-dinle')->assertExitCode(0);
        $this->assertSame('kapali', app(TerminalStateStore::class)->listenerState()['durum']);

        app(TerminalStateStore::class)->putPushSettings(true, 7005);
        $this->artisan('kurs:terminal-dinle', ['--port' => $this->closedPort(), '--sure' => 0.3])->assertExitCode(0);
        $this->assertSame('kapali', app(TerminalStateStore::class)->listenerState()['durum']);
        $this->assertNotNull(app(TerminalStateStore::class)->listenerState()['kapandi'] ?? null);
    }

    public function test_push_settings_api_shows_what_to_type_into_device_menu(): void
    {
        $device = $this->actingAdminOnLocalNode();
        [, $port] = $this->silentServer();
        $device->forceFill(['protocol' => 'perkotek_fk', 'zk_ip' => '127.0.0.1', 'zk_port' => $port])->save();
        app(TerminalStateStore::class)->putDevice($device->id, ['kopru_ip' => '192.168.68.176', 'son_test' => now()->toIso8601String()]);

        $this->postJson('/api/v1/attendance/terminal/push', ['acik' => true, 'port' => 80])->assertStatus(422);
        $this->postJson('/api/v1/attendance/terminal/push', ['acik' => true, 'port' => 7005, 'aktar_ip' => '192.168.68.5'])->assertStatus(422);

        $this->postJson('/api/v1/attendance/terminal/push', ['acik' => true, 'port' => 7005, 'aktar_ip' => '192.168.68.5', 'aktar_port' => 7005])->assertOk()
            ->assertJsonPath('push.durum', 'baslatiliyor')
            ->assertJsonPath('push.cihaz_menusu.server_ip', '192.168.68.176')
            ->assertJsonPath('push.cihaz_menusu.port', 7005)
            ->assertJsonPath('push.aktar_ip', '192.168.68.5');

        app(TerminalStateStore::class)->putListenerState(['durum' => 'aktif', 'port' => 7005, 'kalp' => now()->toIso8601String(), 'son_ip' => '192.168.68.60'], true);
        $this->getJson('/api/v1/attendance/terminal/teshis')->assertOk()
            ->assertJsonPath('push_dinleyici.durum', 'aktif')->assertJsonPath('push_dinleyici.son_ip', '192.168.68.60');

        config(['kurs.node' => 'server']);
        $this->postJson('/api/v1/attendance/terminal/push', ['acik' => false, 'port' => 7005])->assertStatus(409);
    }

    // ============================================================ spec v2: geliştirici araçları, analiz, PDKS

    public function test_hex_sender_requires_developer_mode_and_logs_every_byte(): void
    {
        $device = $this->actingAdminOnLocalNode();
        [$server, $port] = $this->silentServer();
        $device->forceFill(['protocol' => 'perkotek_fk', 'zk_ip' => '127.0.0.1', 'zk_port' => $port, 'zk_comm_key' => '123456'])->save();

        $this->postJson('/api/v1/attendance/terminal/oturum', ['ip' => '127.0.0.1', 'port' => $port, 'paketler' => 'A5 5A'])
            ->assertStatus(403)->assertJsonPath('error_code', 'terminal_dev_mode_required');
        $this->postJson('/api/v1/attendance/terminal/ham-tani', ['ip' => '127.0.0.1', 'port' => $port, 'gonderilecek_hex' => 'A5'])->assertStatus(403);

        $this->postJson('/api/v1/attendance/terminal/gelistirici', ['acik' => true])->assertOk();
        $this->postJson('/api/v1/attendance/terminal/oturum', ['ip' => '127.0.0.1', 'port' => $port, 'paketler' => "zz"])->assertStatus(422);

        $res = $this->postJson('/api/v1/attendance/terminal/oturum', [
            'ip' => '127.0.0.1', 'port' => $port, 'paketler' => "A5 5A,01\n0x40 E2 01 00", 'yanit_bekleme_sn' => 0.3, 'cihaz_id' => $device->id,
        ])->assertOk();

        $res->assertJsonPath('tx_bayt', 7)->assertJsonPath('paketler.0.durum', 'zaman_asimi')->assertJsonPath('paketler.1.tx_hex', '40e20100');
        $states = array_column($res->json('durumlar'), 'durum');
        foreach (['Connecting', 'Connected', 'Waiting Response', 'Timeout', 'Connection Closed'] as $st) {
            $this->assertContains($st, $states);
        }

        $conn = stream_socket_accept($server, 1);
        stream_set_timeout($conn, 1);
        $this->assertSame("\xA5\x5A\x01\x40\xE2\x01\x00", fread($conn, 64), 'Yalnız kullanıcının baytları gider');

        $log = $this->getJson('/api/v1/attendance/terminal/raw-log')->assertOk()->json('data');
        $this->assertSame(['40e20100', 'a55a01'], array_values(array_map(fn ($r) => $r['payload_hex'], array_filter($log, fn ($r) => $r['kind'] === 'TX'))));

        // 123456 = 0x0001E240 → LE "40 e2 01 00": dışa aktarımda maskelenir
        $text = $this->get('/api/v1/attendance/terminal/raw-log/indir?maske_sifre=1&maske_ip=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('40 e2 01 00', (string) $text);
        $this->assertStringContainsString('** ** ** **', (string) $text);
        $this->assertStringContainsString('127.0.x.x', (string) $text);

        $this->deleteJson('/api/v1/attendance/terminal/raw-log')->assertOk();
        $this->assertSame([], $this->getJson('/api/v1/attendance/terminal/raw-log')->json('data'));
    }

    public function test_packet_analyzer_suggests_header_length_and_checksum_but_is_only_a_guess(): void
    {
        $make = function (string $payload): string {
            $body = "\xA5\x5A".chr(strlen($payload) + 5).$payload;

            return $body.chr(array_sum(unpack('C*', $body)) & 0xFF).chr(0x0D);
        };
        $packets = [$make("\x01"), $make("\x02\x10\x20"), $make("\x03\xAA\xBB\xCC\xDD")];

        $r = app(\App\Services\Devices\Analysis\PacketAnalyzer::class)->compare($packets, 1);

        $this->assertSame('Otomatik analiz tahmindir; doğrulanmadan sürücüye eklenmez.', $r['uyari']);
        $this->assertSame(2, $r['sabit_baslik'], 'a5 5a sabit; üçüncü bayt uzunluk olduğu için değişir');
        $fields = array_column($r['oneriler'], 'aciklama', 'alan');
        $this->assertArrayHasKey('Sabit başlık', $fields);
        $this->assertStringContainsString('Toplam mod 256', collect($r['oneriler'])->where('alan', 'Sağlama toplamı (aday)')->pluck('aciklama')->implode(' '));
        $this->assertNotEmpty(collect($r['oneriler'])->where('alan', 'Veri uzunluğu (aday)')->where('ofset', 2)->all());
        $this->assertArrayHasKey('Sabit son ek', $fields);
    }

    public function test_har_import_masks_passwords_and_cookies(): void
    {
        $har = json_encode(['log' => ['entries' => [[
            'startedDateTime' => '2026-09-19T10:00:00Z',
            'request' => ['method' => 'POST', 'url' => 'http://192.168.68.60/login.cgi?user=admin&password=admin', 'httpVersion' => 'HTTP/1.1',
                'headers' => [['name' => 'Cookie', 'value' => 'sid=SECRET'], ['name' => 'Content-Type', 'value' => 'application/x-www-form-urlencoded']],
                'postData' => ['text' => 'username=admin&pwd=admin&x=1']],
            'response' => ['status' => 200, 'statusText' => 'OK', 'headers' => [['name' => 'Set-Cookie', 'value' => 'sid=SECRET2']], 'content' => ['mimeType' => 'application/json', 'text' => '{"ok":1,"password":"admin"}']],
        ], [
            'request' => ['method' => 'GET', 'url' => 'http://192.168.68.60/logo.png', 'headers' => []], 'response' => ['status' => 200, 'headers' => [], 'content' => []],
        ]]]]);

        $rows = app(\App\Services\Devices\Analysis\HarImporter::class)->parse((string) $har);

        $this->assertCount(1, $rows, 'Görsel dosyalar atlanır');
        $all = hex2bin($rows[0]['tx_hex']).hex2bin($rows[0]['rx_hex']);
        $this->assertStringNotContainsString('SECRET', $all);
        $this->assertStringNotContainsString('pwd=admin', $all);
        $this->assertStringNotContainsString('password=admin', $all);
        $this->assertStringNotContainsString('"password":"admin"', $all);
        $this->assertStringContainsString('POST /login.cgi', $all);
        $this->assertStringContainsString('username=admin', $all, 'Kullanıcı adı kalır, yalnız parola maskelenir');
    }

    public function test_pdks_staff_scan_goes_to_staff_attendance_and_summary_counts_late(): void
    {
        $device = $this->actingAdminOnLocalNode();
        $teacher = \App\Models\Teacher::query()->create(['branch_id' => $device->branch_id, 'first_name' => 'Ayşe', 'last_name' => 'Öğretmen']);

        $this->postJson('/api/v1/attendance/pdks/eslestir', ['kullanici_no' => '77', 'kisi_turu' => 'teacher', 'kisi_id' => $teacher->id])->assertOk();

        $presence = app(\App\Services\Attendance\PresenceService::class);
        $in = $presence->ingest(['identifier' => '77', 'identifier_kind' => 'fingerprint', 'event_type' => 'AUTO', 'occurred_at' => '2026-09-18 09:20:00', 'idempotency_key' => 't:77:1'], $device);
        $out = $presence->ingest(['identifier' => '77', 'identifier_kind' => 'fingerprint', 'event_type' => 'AUTO', 'occurred_at' => '2026-09-18 17:05:00', 'idempotency_key' => 't:77:2'], $device);
        $dup = $presence->ingest(['identifier' => '77', 'identifier_kind' => 'fingerprint', 'event_type' => 'AUTO', 'occurred_at' => '2026-09-18 17:05:00', 'idempotency_key' => 't:77:2'], $device);

        $this->assertSame(['staff', 'ENTRY'], [$in['status'], $in['event_type']]);
        $this->assertSame('EXIT', $out['event_type']);
        $this->assertSame('duplicate', $dup['status']);
        $this->assertSame(0, \App\Models\DailyPresence::query()->count(), 'Personel okutması öğrenci yoklamasına yazılmaz');

        $sum = $this->getJson('/api/v1/attendance/pdks/ozet?baslangic=2026-09-18&bitis=2026-09-18&kisi_turu=personel&mesai_baslangic=09:00&tolerans_dk=5')->assertOk();
        $sum->assertJsonPath('gunluk.0.kisi', 'Ayşe Öğretmen')->assertJsonPath('gunluk.0.ilk_giris', '09:20')
            ->assertJsonPath('gunluk.0.son_cikis', '17:05')->assertJsonPath('gunluk.0.gec', true)->assertJsonPath('toplam.0.sure', '7 sa 45 dk');

        $this->getJson('/api/v1/attendance/pdks/kayitlar?baslangic=2026-09-18&bitis=2026-09-18&kisi_turu=personel')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.kisi_turu', 'teacher');
    }

    public function test_pdks_linking_confirms_conflicts_and_rematches_pending_scans(): void
    {
        $device = $this->actingAdminOnLocalNode();
        $a = \App\Models\Student::query()->create(['branch_id' => $device->branch_id, 'student_no' => '2024001', 'first_name' => 'Ali', 'last_name' => 'Veli', 'status' => 'active']);
        $b = \App\Models\Student::query()->create(['branch_id' => $device->branch_id, 'student_no' => '2024002', 'first_name' => 'Can', 'last_name' => 'Kaya', 'status' => 'active']);

        app(\App\Services\Attendance\PresenceService::class)->ingest(['identifier' => '5', 'identifier_kind' => 'fingerprint', 'event_type' => 'AUTO', 'occurred_at' => now()->subDay()->format('Y-m-d H:i:s'), 'idempotency_key' => 'x:5:1'], $device);
        $this->getJson('/api/v1/attendance/pdks/kisiler')->assertOk()->assertJsonPath('bekleyen.0.kullanici_no', '5');

        $this->postJson('/api/v1/attendance/pdks/eslestir', ['kullanici_no' => '5', 'kisi_turu' => 'student', 'kisi_id' => $a->id])->assertOk()->assertJsonPath('baglanan_eski_okutma', 1);
        $this->postJson('/api/v1/attendance/pdks/eslestir', ['kullanici_no' => '5', 'kisi_turu' => 'student', 'kisi_id' => $b->id])->assertStatus(409);
        $this->postJson('/api/v1/attendance/pdks/eslestir', ['kullanici_no' => '5', 'kisi_turu' => 'student', 'kisi_id' => $b->id, 'onay' => true])->assertOk();

        $rows = collect($this->postJson('/api/v1/attendance/pdks/csv/onizle', ['icerik' => "cihaz;ogrenci\n5;2024001\n6;2024002\n7;9999"])->assertOk()->json('data'))->keyBy('kullanici_no');
        $this->assertSame('cakisma', $rows['5']['durum']);
        $this->assertSame('yeni', $rows['6']['durum']);
        $this->assertSame('hata', $rows['7']['durum']);

        $this->postJson('/api/v1/attendance/pdks/csv/uygula', ['icerik' => "5;2024001\n6;2024002"])->assertOk()->assertJsonPath('eslenen', 1);
        $this->assertSame($b->id, (int) \App\Models\DeviceIdentity::query()->where('identifier', '5')->value('person_id'), 'Onaysız çakışma değişmez');

        config(['kurs.node' => 'server']);
        $this->postJson('/api/v1/attendance/pdks/eslestir', ['kullanici_no' => '8', 'kisi_turu' => 'student', 'kisi_id' => $a->id])->assertStatus(409);
        $this->getJson('/api/v1/attendance/pdks/kayitlar')->assertOk();
    }

    public function test_yt33_driver_methods_throw_typed_not_implemented_and_scheduler_skips_yt33(): void
    {
        $d = app(Yt33Driver::class);
        foreach (['probe', 'getDeviceInfo', 'getUsers', 'disconnect'] as $m) {
            try {
                $d->{$m}();
                $this->fail("{$m} istisna fırlatmalıydı");
            } catch (\App\Services\Devices\Drivers\Yt33\ProtocolNotImplementedError $e) {
                $this->assertStringStartsWith('Protokol verisi bekleniyor', $e->getMessage());
            }
        }

        $device = $this->actingAdminOnLocalNode();
        $device->forceFill(['protocol' => 'perkotek_fk', 'zk_ip' => '127.0.0.1', 'zk_port' => 5005])->save();
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'kurs:cihaz-cek'));
        if ($event) {
            $this->assertFalse($event->filtersPass($this->app), 'YT33 cihazı varken ZK çekme zamanlayıcısı çalışmamalı');
        }
    }
}
