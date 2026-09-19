<?php

namespace App\Services\Devices\Drivers\Yt33;

use App\Services\Devices\Terminal\DriverResult;
use App\Services\Devices\Terminal\TerminalEndpoint;
use Carbon\CarbonImmutable;

/**
 * YT33 (Perkotek / FK "Dynamic Face") uygulama protokolü sözleşmesi.
 * Doğrulanmamış her yöntem ProtocolNotImplementedError FIRLATIR — asla tahmini paket göndermez.
 */
interface Protocol
{
    /** El sıkışma (Machine ID ve iletişim şifresi uç noktadan). Doğrulanmadıkça ProtocolNotImplementedError. */
    public function connect(TerminalEndpoint $endpoint): DriverResult;

    public function disconnect(): void;

    /** Uygulama katmanı yoklaması (el sıkışma). Ağ/soket sınaması DEĞİL — o TcpProbe'dur. */
    public function probe(): array;

    public function getDeviceInfo(): array;

    public function getUsers(): array;

    public function getAttendanceLogs(?CarbonImmutable $since = null): array;

    public function setUser(string $deviceUserId, string $name, ?string $cardNo = null): void;

    public function deleteUser(string $deviceUserId): void;

    public function syncTime(CarbonImmutable $time): void;

    /** Pull oturumunda cihazın kendiliğinden gönderdiği olaylar (varsa). Push için bkz. Push\Server. */
    public function listenEvents(callable $onEvent, float $seconds): void;
}
