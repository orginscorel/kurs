<?php

namespace App\Services\Devices\Drivers;

use App\Models\Device;

/**
 * SÜRÜCÜ KAYIT DEFTERİ — ekranın marka/protokol listesini buradan okur.
 *
 * `devices.protocol` sütunundaki değer buradaki anahtarla eşleşir. Yeni marka eklemek için
 * yalnız bu dosyaya bir satır ve bir sürücü sınıfı gerekir; ön yüzde hiçbir listeye dokunulmaz.
 */
class DriverRegistry
{
    /** @var array<string, DeviceDriver>|null */
    private ?array $drivers = null;

    public function __construct(private readonly ZkDriver $zk, private readonly AdmsDriver $adms) {}

    /** @return array<string, DeviceDriver> */
    public function all(): array
    {
        return $this->drivers ??= [
            'zk' => $this->zk,
            'adms' => $this->adms,
            'hikvision' => new PlannedDriver(
                'hikvision', 'Hikvision', ['Hikvision DS-K1T serisi', 'Hikvision yüz tanıma terminalleri'],
                'Hikvision ISAPI (HTTP + Digest kimlik doğrulama) katmanı planlandı, henüz yazılmadı. Şimdilik cihaz kaydı açılabilir ama kayıt çekilemez.',
            ),
            'anviz' => new PlannedDriver(
                'anviz', 'Anviz', ['Anviz C2/W2 serisi', 'Anviz CrossChex uyumlu terminaller'],
                'Anviz TC/IP protokolü planlandı, henüz yazılmadı. Şimdilik cihaz kaydı açılabilir ama kayıt çekilemez.',
            ),
        ];
    }

    public function find(?string $key): ?DeviceDriver
    {
        return $this->all()[$key ?? ''] ?? null;
    }

    /** Cihaz kaydının sürücüsü; protokol boşsa ZKTeco varsayılır (eski kayıtlar). */
    public function for(Device $device): DeviceDriver
    {
        return $this->find($device->protocol) ?? $this->zk;
    }

    /** Ekran için tam katalog. */
    public function catalog(): array
    {
        return array_values(array_map(fn (DeviceDriver $d) => [
            'anahtar' => $d->key(),
            'etiket' => $d->label(),
            'markalar' => $d->vendors(),
            'durum' => $d->status(),
            'yetenekler' => $d->capabilities(),
            'alanlar' => $d->fields(),
            'kurulum' => $d->setupSteps(),
        ], $this->all()));
    }
}
