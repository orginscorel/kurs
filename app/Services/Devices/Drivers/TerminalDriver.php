<?php

namespace App\Services\Devices\Drivers;

use App\Services\Devices\Network\TcpProbeResult;
use App\Services\Devices\Terminal\Data\AttendanceEvent;
use App\Services\Devices\Terminal\Data\DeviceUser;
use App\Services\Devices\Terminal\DriverResult;
use App\Services\Devices\Terminal\TerminalEndpoint;
use Carbon\CarbonImmutable;

/**
 * AĞ ÜZERİNDEN KONUŞULAN TERMİNAL SÜRÜCÜSÜ (bizim bağlandığımız ya da cihazın bize gönderdiği).
 *
 * Her yöntem tiplenmiş DriverResult döner. Kural: protokolü DOĞRULANMAMIŞ bir işlem ASLA
 * tahmini paket göndermez ve sahte veri döndürmez → DriverStatus::ProtocolNotImplemented.
 *
 * Sürücü seçimi cihaz kaydındadır (`devices.protocol` = key()).
 */
interface TerminalDriver extends DeviceDriver
{
    /** Bu sürücünün varsayılan portu (ör. ZKTeco 4370). Bilinmiyorsa null — ekran kullanıcıdan ister. */
    public function defaultPort(): ?int;

    /** Desteklenen aktarımlar ('tcp', 'udp'). */
    public function transports(): array;

    /** Protokol doğrulanmış mı? false ise pull/kimlik yöntemleri ProtocolNotImplemented döner. */
    public function protocolVerified(): bool;

    /** AŞAMA 1: yalnız ağ/soket (hiç bayt göndermeden). */
    public function testNetwork(TerminalEndpoint $endpoint): TcpProbeResult;

    /** AŞAMA 2a: protokol el sıkışması (bağlan → kapat). */
    public function connect(TerminalEndpoint $endpoint): DriverResult;

    /** AŞAMA 2b: künye (seri no, model, yazılım …). Ok ise data = array. */
    public function identifyDevice(TerminalEndpoint $endpoint): DriverResult;

    /** @return DriverResult<list<DeviceUser>> */
    public function fetchUsers(TerminalEndpoint $endpoint, int $deviceId): DriverResult;

    /** @return DriverResult<list<AttendanceEvent>> */
    public function fetchAttendanceLogs(TerminalEndpoint $endpoint, int $deviceId, ?CarbonImmutable $since = null): DriverResult;

    /**
     * Cihazın kendisinin gönderdiği (push) ham paketi olaylara çevirir.
     *
     * @return DriverResult<list<AttendanceEvent>>
     */
    public function parsePush(string $payload, int $deviceId): DriverResult;
}
