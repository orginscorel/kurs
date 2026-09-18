<?php

namespace App\Services\Devices\Drivers;

use App\Models\Device;

/**
 * HENÜZ YAZILMAMIŞ sürücüler (Hikvision, Anviz …).
 *
 * Bilinçli olarak yalnız ARAYÜZ vardır: marka listede görünür, seçilince ne gerektiği ve
 * ne zaman geleceği yazar. Yarım yazılmış bir protokol, hiç olmamasından kötüdür — yanlış
 * okunan bir giriş kaydı yanlış velinin telefonuna bildirim gönderir.
 */
class PlannedDriver implements DeviceDriver
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly array $vendors,
        private readonly string $note,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function vendors(): array
    {
        return $this->vendors;
    }

    public function status(): string
    {
        return 'yakinda';
    }

    public function capabilities(): array
    {
        return ['tarama' => false, 'cekme' => false, 'itme' => false, 'kullanicilar' => false];
    }

    public function fields(): array
    {
        return [
            ['ad' => 'ip', 'etiket' => 'IP adresi', 'tur' => 'ip', 'ipucu' => 'Şimdilik yalnız not olarak saklanır.'],
            ['ad' => 'serial_no', 'etiket' => 'Seri numarası', 'tur' => 'metin'],
        ];
    }

    public function setupSteps(): array
    {
        return [$this->note];
    }

    public function test(Device $device): array
    {
        return [
            'durum' => 'desteklenmiyor',
            'mesaj' => "{$this->label} için bağlantı katmanı henüz yazılmadı.",
            'oneri' => $this->note,
        ];
    }
}
