<?php

namespace Tests\Unit;

use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\DailyPresence;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Student;
use App\Services\Attendance\ZkPullService;
use App\Services\Devices\Zk\ZkConnectionSettings;
use App\Services\Devices\Zk\ZkTerminal;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeZkDevice;
use Tests\TestCase;

/**
 * İÇE AKTARMA BORU HATTI — sahte cihazdan çekilen kayıtlar MEVCUT yoklama yapısına yazılır.
 *
 * Bellek içi SQLite üzerinde gerçek migration'larla çalışır; canlı veritabanına DOKUNMAZ.
 * Kanıtlanan davranışlar: eşleşme, günlük özet, yinelenen kayıt engeli (idempotency),
 * eşleşmeyen kaydın kaybolmaması, imleç ilerlemesi ve cihaz durumunun güncellenmesi.
 */
class ZkPullPipelineTest extends TestCase
{
    private Branch $branch;

    private Device $device;

    /** @var list<array{0:mixed,1:array}> */
    private array $running = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        config(['kurs.silent_events' => true, 'kurs.node' => 'local']);
        $this->artisan('migrate', ['--force' => true])->run();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);

        $this->device = Device::query()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Ana Giriş Terminali',
            'kind' => 'fingerprint',
            'direction' => 'both',
            'api_token_hash' => str_repeat('a', 64),
            'api_token_prefix' => 'dev_test0001',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->running as [$process, $pipes]) {
            FakeZkDevice::stop($process, $pipes);
        }
        $this->running = [];
        parent::tearDown();
    }

    private function student(string $no, string $first, string $last, ?string $deviceUserId = null): Student
    {
        $student = Student::query()->create([
            'branch_id' => $this->branch->id,
            'student_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => "{$first} {$last}",
            'status' => 'active',
        ]);

        if ($deviceUserId !== null) {
            DeviceIdentity::query()->create([
                'branch_id' => $this->branch->id,
                'person_type' => 'student',
                'person_id' => $student->id,
                'kind' => 'fingerprint',
                'identifier' => $deviceUserId,
                'is_active' => true,
            ]);
        }

        return $student;
    }

    private function terminal(array $scenario): ZkTerminal
    {
        [$process, $port, $pipes] = FakeZkDevice::start($scenario);
        $this->running[] = [$process, $pipes];

        return ZkTerminal::open(ZkConnectionSettings::fromArray([
            'host' => '127.0.0.1', 'port' => $port, 'transport' => $scenario['transport'] ?? 'tcp',
            'connect_timeout' => 3, 'read_timeout' => 10,
        ]));
    }

    // =================================================================

    public function test_matched_records_become_attendance_events_and_daily_presence(): void
    {
        $ayse = $this->student('2026001', 'Ayşe', 'Yılmaz', '1001');
        $base = CarbonImmutable::now()->startOfDay()->addHours(8);

        // 4 kayıt: 1001, 1002, 1003, 1001 — yalnız 1001 eşlenmiş
        $result = app(ZkPullService::class)->pull($this->device, true, $this->terminal([
            'records' => 4, 'record_size' => 40, 'base_time' => $base->format('Y-m-d H:i:s'),
        ]));

        $this->assertSame(4, $result['okunan']);
        $this->assertSame(2, $result['islenen']);        // 1001'in 08:00 girişi + 08:21 çıkışı
        $this->assertSame(2, $result['eslesmeyen']);     // 1002, 1003 eşlenmemiş
        $this->assertSame(0, $result['yoksayilan']);

        $event = AttendanceEvent::query()->where('student_id', $ayse->id)->orderBy('occurred_at')->first();
        $this->assertNotNull($event);
        $this->assertSame('ENTRY', $event->event_type);
        $this->assertSame('fingerprint', $event->source);
        $this->assertSame('1001', $event->raw_identifier);
        $this->assertTrue($event->is_matched);
        $this->assertSame($this->device->id, $event->device_id);

        $presence = DailyPresence::query()->where('student_id', $ayse->id)->first();
        $this->assertNotNull($presence);
        $this->assertSame($base->format('Y-m-d H:i:s'), $presence->first_entry_at->format('Y-m-d H:i:s'));
    }

    public function test_same_record_is_never_processed_twice(): void
    {
        $this->student('2026001', 'Ayşe', 'Yılmaz', '1001');
        $base = CarbonImmutable::now()->startOfDay()->addHours(8);
        $scenario = ['records' => 3, 'record_size' => 40, 'base_time' => $base->format('Y-m-d H:i:s')];

        $first = app(ZkPullService::class)->pull($this->device, true, $this->terminal($scenario));
        $countAfterFirst = AttendanceEvent::query()->count();

        // Aynı cihaz, aynı kayıtlar: --tam ile hepsi tekrar okunur ama hiçbiri yeniden yazılmaz.
        $second = app(ZkPullService::class)->pull($this->device->fresh(), true, $this->terminal($scenario));

        $this->assertSame(3, $first['okunan']);
        $this->assertSame(3, $second['okunan']);
        $this->assertSame(3, $second['yinelenen']);
        $this->assertSame(0, $second['islenen']);
        $this->assertSame($countAfterFirst, AttendanceEvent::query()->count());
    }

    public function test_idempotency_key_encodes_device_user_and_moment(): void
    {
        $this->student('2026001', 'Ayşe', 'Yılmaz', '1001');
        $base = CarbonImmutable::now()->startOfDay()->addHours(8);

        app(ZkPullService::class)->pull($this->device, true, $this->terminal([
            'records' => 1, 'record_size' => 40, 'base_time' => $base->format('Y-m-d H:i:s'),
        ]));

        $key = AttendanceEvent::query()->value('idempotency_key');
        $this->assertSame("zk:{$this->device->id}:1001:".$base->getTimestamp(), $key);
    }

    public function test_unmatched_records_are_kept_for_later_matching(): void
    {
        // Hiçbir öğrenci eşlenmemiş: 3 kayıt da "eşleşmemiş" olarak BEKLER, kaybolmaz.
        $result = app(ZkPullService::class)->pull($this->device, true, $this->terminal([
            'records' => 3, 'record_size' => 40, 'base_time' => CarbonImmutable::now()->startOfDay()->addHours(8)->format('Y-m-d H:i:s'),
        ]));

        $this->assertSame(3, $result['eslesmeyen']);
        $this->assertSame(3, AttendanceEvent::query()->where('is_matched', false)->count());
        $this->assertSame(['1001', '1002', '1003'], AttendanceEvent::query()->orderBy('occurred_at')->pluck('raw_identifier')->all());
        $this->assertSame(0, DailyPresence::query()->count());
    }

    public function test_cursor_and_device_status_are_updated(): void
    {
        $this->student('2026001', 'Ayşe', 'Yılmaz', '1001');
        $base = CarbonImmutable::now()->startOfDay()->addHours(8);

        app(ZkPullService::class)->pull($this->device, true, $this->terminal([
            'records' => 4, 'record_size' => 40, 'base_time' => $base->format('Y-m-d H:i:s'),
        ]));

        $device = $this->device->fresh();
        $this->assertSame('ok', $device->zk_last_status);
        $this->assertNull($device->zk_last_error);
        $this->assertSame(4, $device->zk_last_record_count);
        $this->assertSame($base->addMinutes(21)->format('Y-m-d H:i:s'), $device->zk_cursor_at->format('Y-m-d H:i:s'));
        $this->assertSame('YT33-TEST-0001', $device->serial_no);
        $this->assertNotNull($device->last_seen_at);          // köprü canlı → otomatik "gelmedi" durmaz
    }

    public function test_connection_failure_is_recorded_on_the_device(): void
    {
        $this->device->forceFill(['protocol' => 'zk', 'zk_ip' => '127.0.0.1', 'zk_port' => 1, 'zk_transport' => 'tcp'])->save();

        try {
            app(ZkPullService::class)->pull($this->device);
            $this->fail('Kapalı porta bağlanılmamalıydı.');
        } catch (\App\Services\Devices\Zk\Exceptions\ZkConnectionException) {
            // beklenen
        }

        $device = $this->device->fresh();
        $this->assertSame('error', $device->zk_last_status);
        $this->assertStringContainsString('baglanti', $device->zk_last_error);
        $this->assertNull($device->zk_cursor_at);
    }

    public function test_comm_key_is_stored_encrypted(): void
    {
        // Kurum veri anahtarı yerel düğümde zorunlu; CI ortamında .env'den gelmez, testte kur
        config(['kurs.data_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->device->zk_comm_key = '123456';
        $this->device->save();

        $raw = DB::table('devices')->where('id', $this->device->id)->value('zk_comm_key_encrypted');

        $this->assertNotSame('123456', $raw);
        $this->assertNotEmpty($raw);
        $this->assertSame('123456', $this->device->fresh()->zk_comm_key);
        $this->assertArrayNotHasKey('zk_comm_key_encrypted', $this->device->fresh()->toArray());
    }

    public function test_device_direction_forces_event_type(): void
    {
        $this->student('2026001', 'Ayşe', 'Yılmaz', '1001');
        $this->device->forceFill(['direction' => 'entry'])->save();
        $base = CarbonImmutable::now()->startOfDay()->addHours(8);

        app(ZkPullService::class)->pull($this->device, true, $this->terminal([
            'records' => 4, 'record_size' => 40, 'base_time' => $base->format('Y-m-d H:i:s'),
        ]));

        // Cihazın punch kodu ÇIKIŞ dese bile, "yalnız giriş" cihazında olay GİRİŞ olarak yazılır.
        $this->assertSame(2, AttendanceEvent::query()->where('is_matched', true)->count());
        $this->assertSame(['ENTRY'], AttendanceEvent::query()->where('is_matched', true)->pluck('event_type')->unique()->values()->all());
    }
}
