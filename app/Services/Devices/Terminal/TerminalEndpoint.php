<?php

namespace App\Services\Devices\Terminal;

use App\Models\Device;

/**
 * Bir terminale nasıl ulaşılacağı (sürücüden bağımsız). İletişim şifresi hiçbir dizi/günlük
 * çıktısında yer almaz: toArray() ve __debugInfo() yalnız "tanımlı mı?" bilgisini verir.
 *
 * Not: sütun adları tarihsel olarak zk_* (zk_ip, zk_port …); artık tüm sürücüler için genel
 * "terminal IP/port" anlamındadır.
 */
final class TerminalEndpoint
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $transport = 'tcp',
        #[\SensitiveParameter] private readonly ?string $commKey = null,
        public readonly float $connectTimeout = 3.0,
        public readonly float $readTimeout = 10.0,
        public readonly ?int $deviceId = null,
    ) {}

    public static function fromDevice(Device $device, int $fallbackPort): self
    {
        return new self(
            host: (string) $device->zk_ip,
            port: (int) ($device->zk_port ?: $fallbackPort),
            transport: $device->zk_transport === 'udp' ? 'udp' : 'tcp',
            commKey: $device->zk_comm_key,
            connectTimeout: (float) config('devices_zk.connect_timeout', 3),
            readTimeout: (float) config('devices_zk.read_timeout', 10),
            deviceId: $device->id,
        );
    }

    public function commKey(): ?string
    {
        return $this->commKey;
    }

    public function label(): string
    {
        return "{$this->host}:{$this->port}/{$this->transport}";
    }

    public function toArray(): array
    {
        return ['ip' => $this->host, 'port' => $this->port, 'aktarim' => $this->transport, 'sifre_tanimli' => $this->commKey !== null];
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
