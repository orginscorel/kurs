<?php

namespace App\Services\Devices\Zk;

/** Cihaz künyesi (salt okunur). Komut çıktısında ve API'de aynen gösterilir. */
final class ZkDeviceInfo
{
    public function __construct(
        public readonly ?string $serialNumber = null,
        public readonly ?string $deviceName = null,
        public readonly ?string $platform = null,
        public readonly ?string $firmware = null,
        public readonly ?string $fingerAlgorithm = null,
        public readonly ?string $macAddress = null,
        public readonly ?int $userCount = null,
        public readonly ?int $fingerCount = null,
        public readonly ?int $recordCount = null,
        public readonly ?int $userCapacity = null,
        public readonly ?int $recordCapacity = null,
        public readonly ?string $deviceTime = null,
    ) {}

    public function toArray(): array
    {
        return [
            'seri_no' => $this->serialNumber,
            'cihaz_adi' => $this->deviceName,
            'platform' => $this->platform,
            'yazilim' => $this->firmware,
            'parmak_algoritmasi' => $this->fingerAlgorithm,
            'mac' => $this->macAddress,
            'kullanici_sayisi' => $this->userCount,
            'parmak_sayisi' => $this->fingerCount,
            'kayit_sayisi' => $this->recordCount,
            'kullanici_kapasitesi' => $this->userCapacity,
            'kayit_kapasitesi' => $this->recordCapacity,
            'cihaz_saati' => $this->deviceTime,
        ];
    }
}
