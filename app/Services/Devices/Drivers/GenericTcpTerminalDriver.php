<?php

namespace App\Services\Devices\Drivers;

use App\Services\Devices\Terminal\DriverResult;
use App\Services\Devices\Terminal\TerminalEndpoint;
use Carbon\CarbonImmutable;

/**
 * GENEL TCP — protokolü bilinmeyen herhangi bir terminal. Yalnız ağ/soket sınaması ve ham TCP
 * tanılaması yapar; cihazla "konuşmaya" çalışmaz. Yeni bir marka için ilk teşhis aracıdır.
 */
class GenericTcpTerminalDriver extends AbstractTerminalDriver
{
    private const MSG = 'Genel TCP sürücüsü yalnız ağ bağlantısını sınar; cihaz protokolü konuşmaz.';

    private const HINT = 'Cihazın markasına uygun sürücüyü seçin. Protokolü bilinmeyen cihazlar için Terminal Teşhis › Ham TCP tanılaması kullanılabilir.';

    public function key(): string
    {
        return 'generic_tcp';
    }

    public function label(): string
    {
        return 'Genel TCP (yalnız ağ testi)';
    }

    public function vendors(): array
    {
        return ['Protokolü bilinmeyen terminaller'];
    }

    public function capabilities(): array
    {
        return ['tarama' => false, 'cekme' => false, 'itme' => false, 'kullanicilar' => false, 'ag_testi' => true, 'ham_tani' => true];
    }

    public function defaultPort(): ?int
    {
        return null;
    }

    public function transports(): array
    {
        return ['tcp'];
    }

    public function protocolVerified(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            ['ad' => 'ip', 'etiket' => 'IP adresi', 'tur' => 'ip', 'zorunlu' => true],
            ['ad' => 'port', 'etiket' => 'Port', 'tur' => 'sayi', 'zorunlu' => true],
            $this->directionField(),
        ];
    }

    public function setupSteps(): array
    {
        return ['Cihazın IP adresini ve dinlediği TCP portunu girin; "Bağlantıyı test et" yalnız ağ erişimini sınar.'];
    }

    public function connect(TerminalEndpoint $endpoint): DriverResult
    {
        return DriverResult::unsupported(self::MSG, self::HINT);
    }

    public function identifyDevice(TerminalEndpoint $endpoint): DriverResult
    {
        return DriverResult::unsupported(self::MSG, self::HINT);
    }

    public function fetchUsers(TerminalEndpoint $endpoint, int $deviceId): DriverResult
    {
        return DriverResult::unsupported(self::MSG, self::HINT);
    }

    public function fetchAttendanceLogs(TerminalEndpoint $endpoint, int $deviceId, ?CarbonImmutable $since = null): DriverResult
    {
        return DriverResult::unsupported(self::MSG, self::HINT);
    }
}
