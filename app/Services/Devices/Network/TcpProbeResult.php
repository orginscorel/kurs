<?php

namespace App\Services\Devices\Network;

/**
 * AŞAMA 1 sonucu: yalnız ağ/soket. Protokolden hiç haberi yoktur.
 * `connected` true ise soket açılmıştır — bu durumda hiçbir ekran "cihaza bağlanılamadı" demez.
 */
final class TcpProbeResult
{
    public function __construct(
        public readonly bool $connected,
        public readonly string $host,
        public readonly int $port,
        public readonly int $durationMs,
        public readonly ?string $localIp = null,
        public readonly ?int $localPort = null,
        public readonly int $errno = 0,
        public readonly string $errstr = '',
        public readonly ?SocketFailure $failure = null,
        public readonly bool $macLocalNetworkSuspect = false,
        public readonly string $message = '',
        public readonly string $hint = '',
    ) {}

    public function toArray(): array
    {
        return [
            'baglandi' => $this->connected,
            'uzak_ip' => $this->host,
            'uzak_port' => $this->port,
            'sure_ms' => $this->durationMs,
            'yerel_ip' => $this->localIp,
            'yerel_port' => $this->localPort,
            'hata_no' => $this->errno ?: null,
            'hata' => $this->errstr !== '' ? $this->errstr : null,
            'neden' => $this->failure?->value,
            'macos_yerel_ag_izni_olasi' => $this->macLocalNetworkSuspect,
            'mesaj' => $this->message,
            'oneri' => $this->hint,
        ];
    }
}
