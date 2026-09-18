<?php

namespace Tests\Unit;

use App\Services\Devices\Discovery\DeviceDiscoveryService;
use App\Services\Devices\Discovery\NetworkProbe;
use App\Services\Devices\Discovery\PortSweep;
use App\Services\Devices\Discovery\ZkBroadcast;
use Tests\Support\FakeZkDevice;
use Tests\TestCase;

/**
 * AĞ TARAMASI KANITI — gerçek soketlerle, dış ağa çıkmadan.
 *
 * Elimizde cihaz yok; bu yüzden 127.0.0.1'de GERÇEKTEN dinleyen sahte bir terminal
 * başlatılır ve tarayıcı onu bulur. Süpürme hızı da gerçek ölçülür: 254 adres tek tek
 * denense (300 ms × 254) 76 saniye sürerdi; eşzamanlı süpürme saniyeler içinde biter.
 *
 * 127.0.0.0/8'in tamamı geri döngüdür (loopback): 254 adresi taramak hiçbir paketi
 * makinenin dışına çıkarmaz, kapalı adresler anında "reddedildi" döner.
 */
class DeviceDiscoveryTest extends TestCase
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

    private function fakeDevice(array $scenario = []): int
    {
        [$process, $port, $pipes] = FakeZkDevice::start($scenario);
        $this->running[] = [$process, $pipes];

        return $port;
    }

    // ================================================================= süpürme

    public function test_sweep_finds_the_listening_address_and_skips_closed_ones(): void
    {
        $port = $this->fakeDevice(['records' => 2]);

        $hosts = ['127.0.0.2', '127.0.0.3', '127.0.0.1', '127.0.0.4'];
        $result = (new PortSweep)->scan($hosts, $port, 0.3);

        $this->assertSame(['127.0.0.1'], $result['acik'], 'Yalnız dinleyen adres açık görünmeli.');
        $this->assertSame(4, $result['denenen']);
        $this->assertFalse($result['kesildi']);
    }

    public function test_sweep_of_254_addresses_finishes_well_under_the_budget(): void
    {
        $port = $this->fakeDevice(['records' => 1]);

        $hosts = [];
        for ($i = 1; $i <= 254; $i++) {
            $hosts[] = "127.0.0.{$i}";
        }

        $started = microtime(true);
        $result = (new PortSweep)->scan($hosts, $port, 0.3, 128, 15.0);
        $elapsed = microtime(true) - $started;

        $this->assertSame(254, $result['denenen']);
        $this->assertContains('127.0.0.1', $result['acik']);
        $this->assertFalse($result['kesildi'], 'Tarama süre sınırına takılmamalı.');
        $this->assertLessThan(15.0, $elapsed, '254 adres 15 saniyenin altında taranmalı.');
    }

    public function test_sweep_never_hangs_when_nothing_answers(): void
    {
        // 10.255.255.x özel adrestir ve yönlendirilmez; hiçbir yanıt gelmez.
        $started = microtime(true);
        $result = (new PortSweep)->scan(['10.255.255.1', '10.255.255.2'], 4370, 0.3, 128, 5.0);

        $this->assertSame([], $result['acik']);
        $this->assertLessThan(5.0, microtime(true) - $started, 'Zaman aşımı zorunlu: çağrı asılı kalmamalı.');
    }

    // ================================================================= adres üretimi

    public function test_network_probe_expands_a_private_slash24(): void
    {
        $hosts = (new NetworkProbe)->hostsOf('192.168.1.0/24');

        $this->assertCount(254, $hosts);
        $this->assertSame('192.168.1.1', $hosts[0]);
        $this->assertSame('192.168.1.254', $hosts[253]);
    }

    public function test_network_probe_refuses_public_ranges(): void
    {
        // İnternet taraması YAPILMAZ: genel IP bloğu istendiğinde boş liste döner.
        $this->assertSame([], (new NetworkProbe)->hostsOf('8.8.8.0/24'));
        $this->assertSame([], (new NetworkProbe)->hostsOf('46.224.208.0/24'));
        $this->assertSame([], (new NetworkProbe)->hostsOf('192.168.1.0/8'));   // fazla geniş
    }

    public function test_network_probe_computes_broadcast_address(): void
    {
        $probe = new NetworkProbe;

        $this->assertSame('192.168.1.255', $probe->broadcastOf('192.168.1.0/24'));
        $this->assertSame('10.0.255.255', $probe->broadcastOf('10.0.0.0/16'));
        $this->assertTrue($probe->isPrivate('192.168.1.50'));
        $this->assertFalse($probe->isPrivate('46.224.208.126'));
    }

    // ================================================================= künye okuma

    public function test_identifies_a_real_listening_terminal(): void
    {
        $port = $this->fakeDevice(['records' => 4, 'serial' => 'YT33-TEST-0001']);

        $found = (new DeviceDiscoveryService(new NetworkProbe, new PortSweep, new ZkBroadcast))
            ->identify('127.0.0.1', $port);

        $this->assertTrue($found->identified);
        $this->assertSame('YT33-TEST-0001', $found->serialNumber);
        $this->assertSame('YT33', $found->model);
        $this->assertSame('ZMM220_TFT', $found->platform);
        $this->assertSame(4, $found->recordCount);
        $this->assertStringContainsString('YT33', $found->suggestedName());
    }

    public function test_identify_reports_a_turkish_remedy_when_the_password_is_missing(): void
    {
        // Cihazda iletişim şifresi tanımlı; şifre verilmediği için kimlik reddedilir.
        $port = $this->fakeDevice(['comm_key' => 123456]);

        $found = (new DeviceDiscoveryService(new NetworkProbe, new PortSweep, new ZkBroadcast))
            ->identify('127.0.0.1', $port);

        $this->assertFalse($found->identified);
        $this->assertSame('kimlik', $found->errorCode);
        $this->assertNotEmpty($found->hint);
        $this->assertStringContainsString('İletişim Şifresi', (string) $found->hint);
    }

    public function test_identify_does_not_throw_when_nothing_listens(): void
    {
        $found = (new DeviceDiscoveryService(new NetworkProbe, new PortSweep, new ZkBroadcast))
            ->identify('127.0.0.1', 1);   // 1 numaralı portta hiçbir şey dinlemiyor

        $this->assertFalse($found->identified);
        $this->assertSame('baglanti', $found->errorCode);
        $this->assertNotEmpty($found->hint);
    }
}
