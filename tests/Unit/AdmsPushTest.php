<?php

namespace Tests\Unit;

use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Student;
use App\Services\Devices\Adms\AdmsService;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADMS / iclock KANITI — cihaz elimizde yok, o yüzden cihazın YAPTIĞI İSTEK taklit edilir.
 *
 * Gerçek terminal ne yaparsa aynısı: GET /iclock/cdata ile ayar ister, POST /iclock/cdata ile
 * TAB ayraçlı ATTLOG satırlarını gönderir, GET /iclock/getrequest ile nabız atar. Kayıtların
 * ZKTeco köprüsüyle AYNI boru hattından (PresenceService) geçtiği ve aynı idempotency
 * deseniyle iki kez yazılmadığı burada doğrulanır.
 */
class AdmsPushTest extends TestCase
{
    private Branch $branch;

    private Device $device;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        config([
            'kurs.silent_events' => true,
            'kurs.data_key' => 'base64:'.base64_encode(random_bytes(32)),
            'devices_adms.enabled' => true,
        ]);
        $this->artisan('migrate', ['--force' => true])->run();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);

        $this->device = Device::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Ana Giriş (ADMS)', 'kind' => 'fingerprint',
            'direction' => 'both', 'serial_no' => 'ADMS-YT33-9001', 'protocol' => 'adms',
            'api_token_hash' => 'x1', 'api_token_prefix' => 'x1', 'is_active' => true,
        ]);

        $this->student = Student::query()->create([
            'branch_id' => $this->branch->id, 'student_no' => 'S-1001', 'first_name' => 'Ayşe',
            'last_name' => 'Yılmaz', 'full_name' => 'Ayşe Yılmaz', 'status' => 'active',
        ]);

        DeviceIdentity::query()->create([
            'branch_id' => $this->branch->id, 'kind' => 'fingerprint', 'identifier' => '1001',
            'person_type' => 'student', 'person_id' => $this->student->id, 'is_active' => true,
        ]);
    }

    // ================================================================= el sıkışma

    public function test_device_handshake_returns_plain_configuration_text(): void
    {
        $response = $this->get('/iclock/cdata?SN=ADMS-YT33-9001&options=all&pushver=2.4.1');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=utf-8');
        $this->assertStringContainsString('GET OPTION FROM: ADMS-YT33-9001', $response->getContent());
        $this->assertStringContainsString('Realtime=1', $response->getContent());

        // El sıkışma aynı zamanda nabızdır: cihaz artık "görüldü".
        $this->assertNotNull($this->device->fresh()->last_seen_at);
    }

    // ================================================================= kayıt gönderimi

    public function test_device_pushes_attendance_rows_into_the_same_pipeline(): void
    {
        $body = "1001\t2026-09-18 08:12:03\t0\t1\t0\t0\n"
               ."1001\t2026-09-18 17:41:55\t1\t1\t0\t0\n";

        $response = $this->call('POST', '/iclock/cdata?SN=ADMS-YT33-9001&table=ATTLOG&Stamp=9999', [], [], [], [], $body);

        $response->assertOk();
        $this->assertStringContainsString('OK', $response->getContent());

        $events = AttendanceEvent::query()->withoutGlobalScope('branch')->orderBy('occurred_at')->get();
        $this->assertCount(2, $events);
        $this->assertTrue((bool) $events[0]->is_matched, 'Kullanıcı numarası öğrenciyle eşlenmiş olmalı.');
        $this->assertSame($this->student->id, $events[0]->student_id);
        $this->assertSame('ENTRY', $events[0]->event_type);
        $this->assertSame('EXIT', $events[1]->event_type);

        $device = $this->device->fresh();
        $this->assertSame('ok', $device->zk_last_status);
        $this->assertSame(2, (int) $device->zk_last_record_count);
        $this->assertSame('ADMS-YT33-9001', $device->serial_no);
        $this->assertSame('9999', $device->adms_stamp);
    }

    public function test_the_same_rows_are_never_written_twice(): void
    {
        $body = "1001\t2026-09-18 08:12:03\t0\t1\n";

        $this->call('POST', '/iclock/cdata?SN=ADMS-YT33-9001&table=ATTLOG', [], [], [], [], $body)->assertOk();
        $this->call('POST', '/iclock/cdata?SN=ADMS-YT33-9001&table=ATTLOG', [], [], [], [], $body)->assertOk();

        $this->assertSame(1, AttendanceEvent::query()->withoutGlobalScope('branch')->count());
    }

    public function test_unmatched_user_numbers_are_kept_not_dropped(): void
    {
        // 7777 hiçbir öğrenciye bağlı değil: kayıt SİLİNMEZ, "bekleyen" olarak durur.
        $body = "7777\t2026-09-18 09:00:00\t0\t1\n";

        $this->call('POST', '/iclock/cdata?SN=ADMS-YT33-9001&table=ATTLOG', [], [], [], [], $body)->assertOk();

        $event = AttendanceEvent::query()->withoutGlobalScope('branch')->first();
        $this->assertNotNull($event);
        $this->assertFalse((bool) $event->is_matched);
        $this->assertSame('7777', $event->raw_identifier);
    }

    // ================================================================= güvenlik

    public function test_unknown_serial_cannot_write_anything(): void
    {
        $body = "1001\t2026-09-18 08:12:03\t0\t1\n";

        $this->call('POST', '/iclock/cdata?SN=SAHTE-CIHAZ-1&table=ATTLOG', [], [], [], [], $body)->assertOk();

        $this->assertSame(0, AttendanceEvent::query()->withoutGlobalScope('branch')->count());

        // Ama yönetici görebilsin diye "tanıtıldı ama kayıtlı değil" listesine düşer.
        $unknown = app(AdmsService::class)->unknownDevices();
        $this->assertSame('SAHTE-CIHAZ-1', $unknown[0]['seri_no']);
    }

    public function test_endpoints_do_nothing_while_disabled(): void
    {
        config(['devices_adms.enabled' => false]);

        $body = "1001\t2026-09-18 08:12:03\t0\t1\n";
        $this->call('POST', '/iclock/cdata?SN=ADMS-YT33-9001&table=ATTLOG', [], [], [], [], $body)->assertOk();

        $this->assertSame(0, AttendanceEvent::query()->withoutGlobalScope('branch')->count());
    }

    public function test_heartbeat_marks_the_device_as_seen(): void
    {
        $this->device->forceFill(['last_seen_at' => null])->save();

        $this->get('/iclock/getrequest?SN=ADMS-YT33-9001&INFO=1')->assertOk()->assertSee('OK');

        $this->assertNotNull($this->device->fresh()->last_seen_at);
    }

    // ================================================================= satır çözümü

    public function test_parses_both_tab_and_space_separated_rows(): void
    {
        $adms = app(AdmsService::class);

        $tabbed = $adms->parseAttlogLine("1042\t2026-09-18 08:05:00\t1\t15");
        $this->assertSame('1042', $tabbed['user']);
        $this->assertSame('2026-09-18 08:05:00', $tabbed['at']->format('Y-m-d H:i:s'));
        $this->assertSame(1, $tabbed['punch']);
        $this->assertSame(15, $tabbed['verify']);

        $spaced = $adms->parseAttlogLine('1042 2026-09-18 08:05:00 0 1');
        $this->assertSame('1042', $spaced['user']);
        $this->assertSame('2026-09-18 08:05:00', $spaced['at']->format('Y-m-d H:i:s'));

        $this->assertNull($adms->parseAttlogLine(''));
        $this->assertNull($adms->parseAttlogLine('bozuk-satir'));
    }
}
