<?php

namespace Tests\Unit;

use App\Services\Devices\Zk\Exceptions\ZkAuthException;
use App\Services\Devices\Zk\Exceptions\ZkConnectionException;
use App\Services\Devices\Zk\ZkConnectionSettings;
use App\Services\Devices\Zk\ZkTerminal;
use Carbon\CarbonImmutable;
use Tests\Support\FakeZkDevice;
use Tests\TestCase;

/**
 * UÇTAN UCA KANIT — cihaz elimizde olmadığı için sahte terminale GERÇEK soketle bağlanılır.
 *
 * Her test ayrı bir PHP sürecinde 127.0.0.1'de dinleyen sahte cihaz başlatır; istemci
 * gerçekten TCP/UDP konuşur, el sıkışır, (gerekirse) iletişim şifresiyle kimlik doğrular,
 * tamponlu ya da düz yoldan veri okur ve kayıtları çözer. Dış ağa çıkılmaz.
 */
class ZkFakeDeviceTest extends TestCase
{
    /** @var list<array{0:mixed,1:array}> */
    private array $running = [];

    protected function tearDown(): void
    {
        foreach ($this->running as [$process, $pipes]) {
            FakeZkDevice::stop($process, $pipes);
        }
        $this->running = [];
        parent::tearDown();
    }

    private function terminal(array $scenario, array $overrides = []): ZkTerminal
    {
        [$process, $port, $pipes] = FakeZkDevice::start($scenario);
        $this->running[] = [$process, $pipes];

        return ZkTerminal::open(ZkConnectionSettings::fromArray([
            'host' => '127.0.0.1',
            'port' => $port,
            'transport' => $scenario['transport'] ?? 'tcp',
            'connect_timeout' => 3,
            'read_timeout' => 10,
        ] + $overrides));
    }

    // ================================================================= el sıkışma + künye

    public function test_connects_over_tcp_and_reads_device_info(): void
    {
        $terminal = $this->terminal(['transport' => 'tcp', 'records' => 3]);
        $info = $terminal->info();

        $this->assertSame('YT33-TEST-0001', $info->serialNumber);
        $this->assertSame('YT33', $info->deviceName);
        $this->assertSame('ZMM220_TFT', $info->platform);
        $this->assertSame('Ver 6.60 Apr 21 2020', $info->firmware);
        $this->assertSame(3, $info->userCount);
        $this->assertSame(3, $info->recordCount);
        $this->assertSame(100000, $info->recordCapacity);
        $this->assertSame('2026-09-15 08:00:00', $info->deviceTime);

        $terminal->close();
    }

    public function test_reads_attendance_records_over_tcp_buffer_path(): void
    {
        $terminal = $this->terminal(['records' => 6, 'record_size' => 40, 'base_time' => '2026-09-16 09:00:00']);
        $records = $terminal->attendance();

        $this->assertCount(6, $records);
        $this->assertSame('1001', $records[0]->userId);
        $this->assertSame('2026-09-16 09:00:00', $records[0]->timestamp->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-16 09:35:00', $records[5]->timestamp->format('Y-m-d H:i:s'));

        $terminal->close();
    }

    public function test_reads_users_without_touching_biometric_templates(): void
    {
        $terminal = $this->terminal(['user_size' => 72]);
        $users = $terminal->users();

        $this->assertCount(3, $users);
        $this->assertSame(['1001', '1002', '1003'], array_map(fn ($u) => $u->userId, $users));
        $this->assertSame('Ayse Yilmaz', $users[0]->name);
        $this->assertSame('Yönetici', $users[2]->privilegeLabel());

        $terminal->close();
    }

    // ================================================================= veri akışı biçimleri

    public function test_prepare_data_stream_is_reassembled(): void
    {
        // Cihaz veriyi CMD_PREPARE_DATA + birden çok CMD_DATA + CMD_ACK_OK olarak gönderir.
        $terminal = $this->terminal(['records' => 60, 'record_size' => 40, 'stream' => 'prepare']);
        $records = $terminal->attendance();

        $this->assertCount(60, $records);
        $this->assertSame('1001', $records[0]->userId);

        $terminal->close();
    }

    public function test_falls_back_to_plain_read_when_buffered_read_is_unsupported(): void
    {
        // Eski aygıt yazılımı CMD_DATA_WRRQ bilmez → istemci düz CMD_ATTLOG_RRQ yoluna düşer.
        $terminal = $this->terminal(['records' => 4, 'record_size' => 16, 'buffered' => false, 'stream' => 'prepare']);
        $records = $terminal->attendance();

        $this->assertCount(4, $records);
        $this->assertSame('1001', $records[0]->userId);

        $terminal->close();
    }

    public function test_large_dataset_is_read_in_chunks(): void
    {
        // 2000 kayıt × 40 bayt = 80.004 bayt → tek parçaya (65.472) sığmaz, iki parçada okunur.
        $terminal = $this->terminal(['records' => 2000, 'record_size' => 40]);
        $records = $terminal->attendance(null, 20000);

        $this->assertCount(2000, $records);
        $this->assertSame('1001', $records[0]->userId);

        $terminal->close();
    }

    public function test_works_over_udp_transport(): void
    {
        $terminal = $this->terminal(['transport' => 'udp', 'records' => 3, 'record_size' => 16]);

        $this->assertSame('YT33-TEST-0001', $terminal->info()->serialNumber);
        $this->assertCount(3, $terminal->attendance());

        $terminal->close();
    }

    // ================================================================= imleç + saat

    public function test_cursor_returns_only_newer_records(): void
    {
        $terminal = $this->terminal(['records' => 10, 'base_time' => '2026-09-16 09:00:00']);

        // 7 dakikalık aralıklarla 10 kayıt; 09:30'dan sonrası 5 kayıt (09:35, 09:42, 09:49, 09:56, 10:03)
        $records = $terminal->attendance(CarbonImmutable::parse('2026-09-16 09:30:00'));

        $this->assertCount(5, $records);
        $this->assertSame('2026-09-16 09:35:00', $records[0]->timestamp->format('Y-m-d H:i:s'));

        $terminal->close();
    }

    public function test_device_clock_can_be_read_and_written(): void
    {
        $terminal = $this->terminal([]);

        $this->assertSame('2026-09-15 08:00:00', $terminal->time()->format('Y-m-d H:i:s'));
        $terminal->setTime(CarbonImmutable::parse('2026-09-17 12:00:00'));   // sahte cihaz ACK_OK döner

        $terminal->close();
    }

    // ================================================================= kimlik doğrulama

    public function test_comm_key_authentication_succeeds_with_correct_key(): void
    {
        $terminal = $this->terminal(['comm_key' => 123456, 'records' => 2], ['comm_key' => '123456']);

        $this->assertSame('YT33-TEST-0001', $terminal->info()->serialNumber);
        $this->assertCount(2, $terminal->attendance());

        $terminal->close();
    }

    public function test_wrong_comm_key_is_rejected_with_turkish_message(): void
    {
        $this->expectException(ZkAuthException::class);
        $this->expectExceptionMessage('iletişim şifresini reddetti');

        $this->terminal(['comm_key' => 123456], ['comm_key' => '999999']);
    }

    public function test_missing_comm_key_explains_the_device_menu(): void
    {
        try {
            $this->terminal(['comm_key' => 123456]);
            $this->fail('İletişim şifresi olmadan bağlanılmamalıydı.');
        } catch (ZkAuthException $e) {
            $this->assertStringContainsString('iletişim şifresi', $e->getMessage());
            $this->assertStringContainsString('Comm', $e->hint);
            $this->assertSame('kimlik', $e->code());
        }
    }

    // ================================================================= hata yolları

    public function test_unreachable_device_fails_fast_with_actionable_hint(): void
    {
        $started = microtime(true);

        try {
            ZkTerminal::open(ZkConnectionSettings::fromArray([
                'host' => '127.0.0.1', 'port' => 1, 'transport' => 'tcp', 'connect_timeout' => 2, 'read_timeout' => 2,
            ]));
            $this->fail('Kapalı porta bağlanılmamalıydı.');
        } catch (ZkConnectionException $e) {
            $this->assertSame('baglanti', $e->code());
            $this->assertStringContainsString('bağlanılamadı', $e->getMessage());
            $this->assertStringContainsString('4370', $e->hint);
        }

        // Zaman aşımı ZORUNLU: hiçbir çağrı süresiz beklemez
        $this->assertLessThan(10, microtime(true) - $started);
    }
}
