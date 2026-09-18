<?php

namespace App\Services\Devices\Drivers;

use App\Models\Device;
use App\Services\Devices\Network\TcpProbe;
use App\Services\Devices\Network\TcpProbeResult;
use App\Services\Devices\Terminal\DriverResult;
use App\Services\Devices\Terminal\TerminalConnectionTester;
use App\Services\Devices\Terminal\TerminalEndpoint;

/** Sürücülerin ortak kısmı: soket sınaması ve iki aşamalı test (tek kaynak). */
abstract class AbstractTerminalDriver implements TerminalDriver
{
    public function testNetwork(TerminalEndpoint $endpoint): TcpProbeResult
    {
        return app(TcpProbe::class)->probe($endpoint->host, $endpoint->port, $endpoint->connectTimeout);
    }

    public function parsePush(string $payload, int $deviceId): DriverResult
    {
        return DriverResult::notImplemented(
            'Push paketi alındı fakat bu sürücü için ayrıştırıcı yok.',
            'Ham paket silinmez; Terminal Teşhis ekranında HEX/ASCII olarak görünür.',
        );
    }

    public function status(): string
    {
        return 'hazir';
    }

    /** DeviceDriver::test — iki aşamalı test (ağ → protokol). */
    public function test(Device $device): array
    {
        if (! $device->zk_ip) {
            return [
                'durum' => 'hata',
                'mesaj' => 'Cihazın IP adresi girilmemiş.',
                'oneri' => 'Terminal Köprüsü ekranından cihazın IP adresini ve portunu girin.',
            ];
        }

        return app(TerminalConnectionTester::class)->run($this, TerminalEndpoint::fromDevice($device, $this->defaultPort() ?? 0))->toArray();
    }

    protected function directionField(): array
    {
        return ['ad' => 'direction', 'etiket' => 'Kapı yönü', 'tur' => 'secim', 'varsayilan' => 'both',
            'secenekler' => [['deger' => 'both', 'etiket' => 'Giriş + Çıkış'], ['deger' => 'entry', 'etiket' => 'Yalnız giriş'], ['deger' => 'exit', 'etiket' => 'Yalnız çıkış']]];
    }
}
