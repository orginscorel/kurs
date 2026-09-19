<?php

namespace App\Services\Devices\Drivers\Yt33;

use App\Services\Devices\Terminal\Data\AttendanceEvent;

/** YT33 yanıt/olay ayrıştırıcı — İSKELET (bkz. Codec). */
final class Parser
{
    /** @return list<AttendanceEvent> */
    public function attendance(string $payload, int $deviceId): array
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('yoklama kaydı ayrıştırma');
    }

    public function users(string $payload, int $deviceId): array
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('kullanıcı listesi ayrıştırma');
    }
}
