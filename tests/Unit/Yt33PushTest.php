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
}
