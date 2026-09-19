<?php

namespace Tests\Unit;

use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Devices\Drivers\Yt33\Push\RealtimeIngest;
use App\Services\Devices\Drivers\Yt33\Push\RealtimeProtocol;
use App\Services\Devices\Drivers\Yt33\Push\Server;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * YT33 (Perkotek FK) GERÇEK ZAMANLI PUSH — 19.09.2026'da cihazdan yakalanan çerçeveyle:
 * onay yanıtı (response_code: OK + trans_id), biyometrik şablonun ham kayda hiç yazılmaması,
 * okutmanın yoklamaya/PDKS'ye, kullanıcı kaydının PDKS kişisine işlenmesi. Gerçek soket (127.0.0.1), bellek içi SQLite.
 */
class Yt33PushTest extends TestCase
{
    private Branch $branch;

    private Device $device;

    private const TEMPLATE = 'SlVTVEFGQUtFVEVNUExBVEVfQkFTRTY0X0RBVEFfMTIzNDU2Nzg5MA==';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        config([
            'kurs.silent_events' => true, 'kurs.node' => 'local',
            'logging.channels.terminal' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        ]);
        $this->artisan('migrate', ['--force' => true])->run();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);

        $this->device = Device::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'YT33', 'kind' => 'fingerprint', 'direction' => 'both',
            'api_token_hash' => str_repeat('b', 64), 'api_token_prefix' => 'dev_yt330001', 'is_active' => true,
            'protocol' => 'perkotek_fk', 'zk_ip' => '127.0.0.1',
        ]);
    }

    private static function request(string $code, string $trans, array $body, string $tail = ''): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).$tail;

        return "POST / HTTP/1.0\r\nrequest_code: {$code}\r\ntrans_id: {$trans}\r\ndev_id: B6D30DD3A1580074\r\ndev_model: R6\r\n"
            ."token: abc\r\nContent-Length: ".strlen($json)."\r\n\r\n".$json;
    }

    private function send(string $raw): string
    {
        $listener = app(Server::class);
        $listener->open(0, '127.0.0.1');
        $client = stream_socket_client('tcp://127.0.0.1:'.$listener->port(), $e, $s, 2);
        fwrite($client, $raw);
        stream_set_blocking($client, false);

        $got = '';
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline && ! feof($client)) {
            $listener->tick(0.05);
            $got .= (string) @fread($client, 8192);
        }
        $listener->close();

        return $got;
    }

    private function student(string $name, ?string $deviceUser = null): Student
    {
        [$first, $last] = explode(' ', $name, 2);
        $s = Student::query()->create([
            'branch_id' => $this->branch->id, 'student_no' => (string) random_int(1000, 9999),
            'first_name' => $first, 'last_name' => $last, 'full_name' => $name, 'status' => 'active',
        ]);
        if ($deviceUser) {
            DeviceIdentity::query()->create(['branch_id' => $this->branch->id, 'person_type' => 'student', 'person_id' => $s->id,
                'kind' => 'fingerprint', 'identifier' => $deviceUser, 'is_active' => true]);
        }

        return $s;
    }

    public function test_glog_is_acknowledged_with_trans_id_and_becomes_attendance(): void
    {
        $ali = $this->student('Ali Kaya', '986');

        $reply = $this->send(self::request('realtime_glog', 'RTLogSend', ['ioMode' => 10, 'time' => '20260919083015', 'userId' => '986', 'verifyMode' => 'Fp']));

        $this->assertStringStartsWith("HTTP/1.0 200 OK\r\n", $reply);
        $this->assertStringContainsString("response_code: OK\r\n", $reply);
        $this->assertStringContainsString("trans_id: RTLogSend\r\n", $reply);

        $event = AttendanceEvent::query()->withoutGlobalScopes()->sole();
        $this->assertSame($ali->id, (int) $event->student_id);
        $this->assertSame('2026-09-19 08:30:15', $event->occurred_at->format('Y-m-d H:i:s'));

        $packet = DB::table('terminal_raw_packets')->sole();
        $this->assertSame('parsed', $packet->parse_status);
        $this->assertSame($this->device->id, (int) $packet->device_id);
    }

    public function test_device_retry_of_same_glog_does_not_duplicate(): void
    {
        $this->student('Ali Kaya', '986');
        $req = self::request('realtime_glog', 'RTLogSend', ['ioMode' => 10, 'time' => '20260919083015', 'userId' => '986', 'verifyMode' => 'Fp']);

        $this->send($req);
        $this->assertStringContainsString('response_code: OK', $this->send($req));

        $this->assertSame(1, AttendanceEvent::query()->withoutGlobalScopes()->count());
    }

    public function test_enroll_template_is_never_stored_and_user_is_auto_linked_by_name(): void
    {
        $semih = $this->student('Semih Yılmaz');

        $reply = $this->send(self::request('realtime_enroll_data', 'RTEnrollData', [
            'card' => '', 'fps' => [self::TEMPLATE, self::TEMPLATE], 'name' => 'SEMIH YILMAZ', 'privilege' => 0,
            'userId' => '1001', 'vaildStart' => '20260101', 'vaildEnd' => '20991231',
        ], "\0BIN_1".self::TEMPLATE));

        $this->assertStringContainsString("trans_id: RTEnrollData\r\n", $reply);

        // Şablon hiçbir sütunda yok
        foreach (DB::table('terminal_raw_packets')->get() as $row) {
            foreach ((array) $row as $value) {
                $this->assertStringNotContainsString(self::TEMPLATE, (string) $value);
                $this->assertStringNotContainsString(self::TEMPLATE, (string) base64_decode((string) $value, true));
            }
            $this->assertStringContainsString('gizlendi', (string) base64_decode($row->payload_base64));
        }

        $user = DB::table('terminal_device_users')->sole();
        $this->assertSame('SEMIH YILMAZ', $user->name);
        $this->assertSame(2, (int) $user->fingerprint_count);
        $this->assertSame('linked', $user->link_status);

        $identity = DeviceIdentity::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['student', $semih->id, '1001'], [$identity->person_type, (int) $identity->person_id, $identity->identifier]);
    }

    public function test_ambiguous_or_existing_names_are_not_auto_linked(): void
    {
        $this->student('Ayşe Demir');
        Teacher::query()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ayse', 'last_name' => 'Demir', 'is_active' => true]);

        $this->send(self::request('realtime_enroll_data', 'RTEnrollData', ['fps' => [], 'name' => 'Ayşe Demir', 'userId' => '7']));

        $this->assertSame(0, DeviceIdentity::query()->withoutGlobalScopes()->count());
        $this->assertSame('ambiguous', DB::table('terminal_device_users')->value('link_status'));

        $people = app(\App\Services\Attendance\PdksService::class)->people($this->branch->id);
        $this->assertSame('Ayşe Demir', $people['bekleyen'][0]['cihazdaki_ad']);
    }

    public function test_packets_from_unknown_device_are_acknowledged_and_processed_later(): void
    {
        $this->device->forceFill(['zk_ip' => '10.0.0.9', 'protocol' => 'zk'])->save();
        $this->student('Ali Kaya', '986');

        $reply = $this->send(self::request('realtime_glog', 'RTLogSend', ['time' => '20260919090000', 'userId' => '986', 'verifyMode' => 'Card']));

        $this->assertStringContainsString('response_code: OK', $reply);
        $this->assertSame('unmatched_dev', DB::table('terminal_raw_packets')->value('parse_status'));
        $this->assertSame(0, AttendanceEvent::query()->withoutGlobalScopes()->count());

        $this->device->forceFill(['zk_ip' => '127.0.0.1'])->save();
        $stats = app(RealtimeIngest::class)->reprocess();

        $this->assertSame(1, $stats['islenen']);
        $this->assertSame(1, AttendanceEvent::query()->withoutGlobalScopes()->count());
    }

    public function test_protocol_helpers(): void
    {
        $this->assertSame('ERROR_NO_CMD', preg_match('/response_code: (\S+)/', RealtimeProtocol::replyFor(['request_code' => 'receive_cmd', 'trans_id' => '1']), $m) ? $m[1] : null);
        $this->assertNull(RealtimeProtocol::time('20261340250000'));
        $this->assertSame('sukru isik', RealtimeIngest::nameKey('  ŞÜKRÜ   Işık '));

        [$out, $note] = RealtimeProtocol::redact('{"userId":"1","fps":["'.self::TEMPLATE.'"],"face":"'.self::TEMPLATE.'"}');
        $this->assertSame('{"userId":"1","fps":"[gizlendi]","face":"[gizlendi]"}', $out);
        $this->assertNotNull($note);

        [$same, $none] = RealtimeProtocol::redact('{"ioMode":10,"userId":"986"}');
        $this->assertSame('{"ioMode":10,"userId":"986"}', $same);
        $this->assertNull($none);
    }

    // ============================================================ terminale kayıt sihirbazı

    private function wizard(): \App\Services\Attendance\TerminalEnrollmentService
    {
        return app(\App\Services\Attendance\TerminalEnrollmentService::class);
    }

    public function test_wizard_reserves_sequential_number_and_device_enroll_completes_it(): void
    {
        $ali = $this->student('Ali Kaya');
        $this->student('Veli Can', '1005');

        $session = $this->wizard()->start($this->branch->id, 'student', $ali->id);
        $this->assertSame('1006', $session['cihaz_no']);
        $this->assertSame('bekliyor', $session['durum']);

        // Cihaz, ad olmadan ayrılan numarayla kaydeder
        $this->send(self::request('realtime_enroll_data', 'RTEnrollData', ['card' => '0012345678', 'fps' => [self::TEMPLATE], 'name' => '', 'userId' => '1006']));

        $done = $this->wizard()->status($this->branch->id, $session['id']);
        $this->assertSame('kaydedildi', $done['durum']);
        $this->assertSame(['parmak' => 1, 'kart' => true], ['parmak' => $done['terminal']['parmak'], 'kart' => $done['terminal']['kart']]);
        $this->assertSame(1, DeviceIdentity::query()->withoutGlobalScopes()->where('person_id', $ali->id)->where('identifier', '1006')->count());
        $this->assertSame([], array_filter($this->wizard()->unenrolled($this->branch->id)['kisiler'], fn ($p) => $p['kisi_id'] === $ali->id));
    }

    public function test_wizard_follows_number_chosen_by_device_but_never_steals_anothers(): void
    {
        $ali = $this->student('Ali Kaya');
        $this->student('Veli Can', '77');
        $session = $this->wizard()->start($this->branch->id, 'student', $ali->id);

        // Başka birinin numarasıyla gelen kayıt oturumu tamamlamaz
        $this->send(self::request('realtime_enroll_data', 'RTEnrollData', ['fps' => [], 'userId' => '77']));
        $this->assertSame('bekliyor', $this->wizard()->status($this->branch->id, $session['id'])['durum']);

        // Cihaz kendi boş numarasını verdi → eşleme oraya taşınır, ayrılan numara bırakılır
        $this->send(self::request('realtime_enroll_data', 'RTEnrollData', ['fps' => [self::TEMPLATE], 'userId' => '5']));
        $done = $this->wizard()->status($this->branch->id, $session['id']);
        $this->assertSame('kaydedildi', $done['durum']);
        $this->assertSame('5', $done['cihaz_no']);
        $this->assertSame(['5'], DeviceIdentity::query()->withoutGlobalScopes()->where('person_id', $ali->id)->pluck('identifier')->all());
    }

    public function test_cancelled_wizard_releases_unused_number(): void
    {
        $ali = $this->student('Ali Kaya');
        $session = $this->wizard()->start($this->branch->id, 'student', $ali->id);
        $this->assertSame('1001', $session['cihaz_no']);

        $this->wizard()->cancel($this->branch->id, $session['id']);

        $this->assertSame(0, DeviceIdentity::query()->withoutGlobalScopes()->count());
        $this->assertSame('suresi_doldu', $this->wizard()->status($this->branch->id, $session['id'])['durum']);
    }

    public function test_wizard_api_is_desktop_only_and_returns_session(): void
    {
        $ali = $this->student('Ali Kaya');
        config(['kurs.node' => 'server']);   // kullanıcı hesabı yalnız sunucuda açılır
        $admin = \App\Models\User::query()->create(['branch_id' => $this->branch->id, 'name' => 'Müdür', 'username' => 'mudur9',
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true]);
        foreach (\App\Support\Permissions::all() as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
        }
        \Spatie\Permission\Models\Role::findOrCreate('yonetici', 'web')->syncPermissions(\App\Support\Permissions::all());
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->assignRole('yonetici');
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        config(['kurs.node' => 'local']);

        $this->postJson('/api/v1/attendance/pdks/terminal-kayit', ['kisi_turu' => 'student', 'kisi_id' => $ali->id])
            ->assertCreated()->assertJsonPath('data.cihaz_no', '1001');
        $this->getJson('/api/v1/attendance/pdks/kayitsiz')->assertOk()->assertJsonPath('toplam', 0);

        config(['kurs.node' => 'server']);
        $this->postJson('/api/v1/attendance/pdks/terminal-kayit', ['kisi_turu' => 'student', 'kisi_id' => $ali->id])->assertStatus(409);
    }
}
