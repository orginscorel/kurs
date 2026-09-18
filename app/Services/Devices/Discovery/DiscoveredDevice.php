<?php

namespace App\Services\Devices\Discovery;

/**
 * Ağ taramasında bulunmuş TEK bir aday cihaz.
 *
 * "Bulundu" iki aşamalıdır: (1) port açık (tcpOpen), (2) künye okundu (identified).
 * İkincisi başarısızsa cihaz yine listelenir — kullanıcı iletişim şifresini girip yeniden
 * deneyebilsin diye. `hata`/`oneri` o zaman ne yapılacağını TÜRKÇE anlatır.
 */
final class DiscoveredDevice
{
    public function __construct(
        public readonly string $ip,
        public readonly int $port = 4370,
        public readonly string $transport = 'tcp',
        public readonly string $protocol = 'zk',
        public readonly bool $identified = false,
        public readonly ?string $serialNumber = null,
        public readonly ?string $model = null,
        public readonly ?string $platform = null,
        public readonly ?string $firmware = null,
        public readonly ?string $macAddress = null,
        public readonly ?int $userCount = null,
        public readonly ?int $recordCount = null,
        public readonly ?string $deviceTime = null,
        public readonly ?int $responseMs = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $error = null,
        public readonly ?string $hint = null,
        /** Bu adres/seri no zaten `devices` tablosunda kayıtlıysa kaydın kimliği. */
        public readonly ?int $deviceId = null,
        public readonly ?string $deviceName = null,
        /** Hangi yöntemle bulundu: 'tarama' (TCP süpürme) | 'yayin' (UDP broadcast) | 'kayitli' */
        public readonly string $foundBy = 'tarama',
    ) {}

    public function withRegistration(?int $deviceId, ?string $deviceName): self
    {
        return new self(
            $this->ip, $this->port, $this->transport, $this->protocol, $this->identified,
            $this->serialNumber, $this->model, $this->platform, $this->firmware, $this->macAddress,
            $this->userCount, $this->recordCount, $this->deviceTime, $this->responseMs,
            $this->errorCode, $this->error, $this->hint, $deviceId, $deviceName, $this->foundBy,
        );
    }

    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'port' => $this->port,
            'aktarim' => $this->transport,
            'protokol' => $this->protocol,
            'kunye_okundu' => $this->identified,
            'seri_no' => $this->serialNumber,
            'model' => $this->model,
            'platform' => $this->platform,
            'yazilim' => $this->firmware,
            'mac' => $this->macAddress,
            'kullanici_sayisi' => $this->userCount,
            'kayit_sayisi' => $this->recordCount,
            'cihaz_saati' => $this->deviceTime,
            'yanit_ms' => $this->responseMs,
            'hata_kodu' => $this->errorCode,
            'hata' => $this->error,
            'oneri' => $this->hint,
            'kayitli_mi' => $this->deviceId !== null,
            'cihaz_id' => $this->deviceId,
            'cihaz_adi' => $this->deviceName,
            'bulunma_yontemi' => $this->foundBy,
            'onerilen_ad' => $this->suggestedName(),
        ];
    }

    /** "Ekle" formunun ön dolduracağı ad. */
    public function suggestedName(): string
    {
        $base = trim((string) ($this->model ?: $this->platform ?: 'Yoklama terminali'));
        $tail = $this->serialNumber ? ' · '.$this->serialNumber : ' · '.$this->ip;

        return mb_substr($base.$tail, 0, 80);
    }
}
