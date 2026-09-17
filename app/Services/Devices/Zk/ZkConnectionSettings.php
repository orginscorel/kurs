<?php

namespace App\Services\Devices\Zk;

use App\Models\Device;

/** Tek bir cihaza nasıl bağlanılacağı. Cihaz kaydından ya da doğrudan komut argümanlarından gelir. */
final class ZkConnectionSettings
{
    public function __construct(
        public readonly string $host,
        public readonly int $port = 4370,
        public readonly string $transport = 'tcp',
        public readonly ?string $commKey = null,
        public readonly float $connectTimeout = 3.0,
        public readonly float $readTimeout = 10.0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            host: (string) $data['host'],
            port: (int) ($data['port'] ?? config('devices_zk.port', 4370)),
            transport: ($data['transport'] ?? config('devices_zk.transport', 'tcp')) === 'udp' ? 'udp' : 'tcp',
            commKey: isset($data['comm_key']) && $data['comm_key'] !== '' ? (string) $data['comm_key'] : null,
            connectTimeout: (float) ($data['connect_timeout'] ?? config('devices_zk.connect_timeout', 3)),
            readTimeout: (float) ($data['read_timeout'] ?? config('devices_zk.read_timeout', 10)),
        );
    }

    /** Cihaz kaydındaki zk_* sütunlarından (iletişim şifresi şifreli sütundan çözülür). */
    public static function fromDevice(Device $device): self
    {
        return self::fromArray([
            'host' => (string) $device->zk_ip,
            'port' => $device->zk_port ?: config('devices_zk.port', 4370),
            'transport' => $device->zk_transport ?: config('devices_zk.transport', 'tcp'),
            'comm_key' => $device->zk_comm_key,
        ]);
    }

    public function label(): string
    {
        return "{$this->host}:{$this->port}/{$this->transport}";
    }
}
