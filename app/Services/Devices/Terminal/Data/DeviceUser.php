<?php

namespace App\Services\Devices\Terminal\Data;

/**
 * Cihazdaki kullanıcı — markadan bağımsız. KVKK: biyometrik ŞABLON asla taşınmaz; yalnız sayılar.
 * Eşleme anahtarı `deviceUserId`dir (device_identities.identifier, kind=fingerprint).
 */
final class DeviceUser
{
    public function __construct(
        public readonly int $deviceId,
        public readonly string $deviceUserId,
        public readonly string $name = '',
        public readonly ?string $cardNo = null,
        public readonly ?int $fingerprintCount = null,
        public readonly ?int $faceCount = null,
        public readonly ?int $palmCount = null,
        public readonly bool $passwordExists = false,
        public readonly array $rawData = [],
    ) {}

    public function toArray(): array
    {
        return [
            'cihaz_id' => $this->deviceId,
            'kullanici_no' => $this->deviceUserId,
            'ad' => $this->name,
            'kart' => $this->cardNo,
            'parmak_sayisi' => $this->fingerprintCount,
            'yuz_sayisi' => $this->faceCount,
            'avuc_sayisi' => $this->palmCount,
            'sifre_var' => $this->passwordExists,
        ];
    }
}
