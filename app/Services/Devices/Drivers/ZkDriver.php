<?php

namespace App\Services\Devices\Drivers;

use App\Models\Device;
use App\Services\Attendance\ZkDeviceService;
use App\Services\Devices\Zk\ZkConnectionSettings;

/** ZKTeco ailesi (Perkotek YT-33 dahil) — TCP/UDP 4370, bizim bağlandığımız yol. */
class ZkDriver implements DeviceDriver
{
    public function __construct(private readonly ZkDeviceService $devices) {}

    public function key(): string
    {
        return 'zk';
    }

    public function label(): string
    {
        return 'ZKTeco protokolü (4370)';
    }

    public function vendors(): array
    {
        return ['Perkotek (YT-33 ve diğerleri)', 'ZKTeco', 'Zkteco uyumlu OEM terminaller'];
    }

    public function status(): string
    {
        return 'hazir';
    }

    public function capabilities(): array
    {
        return ['tarama' => true, 'cekme' => true, 'itme' => false, 'kullanicilar' => true];
    }

    public function fields(): array
    {
        return [
            ['ad' => 'ip', 'etiket' => 'IP adresi', 'tur' => 'ip', 'zorunlu' => true, 'ipucu' => 'Cihaz menüsü: Comm > Ethernet. Sabit IP verin, DHCP kapalı olsun.'],
            ['ad' => 'port', 'etiket' => 'Port', 'tur' => 'sayi', 'varsayilan' => 4370, 'ipucu' => 'Fabrika değeri 4370; değiştirmeyin.'],
            ['ad' => 'transport', 'etiket' => 'Bağlantı türü', 'tur' => 'secim', 'varsayilan' => 'tcp',
                'secenekler' => [['deger' => 'tcp', 'etiket' => 'TCP (önerilen)'], ['deger' => 'udp', 'etiket' => 'UDP (eski aygıt yazılımları)']]],
            ['ad' => 'comm_key', 'etiket' => 'İletişim şifresi', 'tur' => 'gizli', 'ipucu' => 'Cihaz menüsü: Comm > İletişim Şifresi. Kapalıysa boş bırakın.'],
            ['ad' => 'direction', 'etiket' => 'Kapı yönü', 'tur' => 'secim', 'varsayilan' => 'both',
                'secenekler' => [['deger' => 'both', 'etiket' => 'Giriş + Çıkış'], ['deger' => 'entry', 'etiket' => 'Yalnız giriş'], ['deger' => 'exit', 'etiket' => 'Yalnız çıkış']]],
        ];
    }

    public function setupSteps(): array
    {
        return [
            'Cihaz menüsü: Comm > Ethernet → sabit IP verin (ör. 192.168.1.50), DHCP kapalı.',
            'Comm > PC Bağlantısı → port 4370 (fabrika değeri).',
            'Comm > İletişim Şifresi → 0 (kapalı) ya da hatırlayacağınız bir sayı.',
            'Sistem > Tarih/Saat → bilgisayarla aynı saat.',
            'Cihaz ve bilgisayar AYNI wifi/ağda olmalı (misafir ağı olmaz).',
        ];
    }

    public function test(Device $device): array
    {
        if (! $device->zk_ip) {
            return [
                'durum' => 'hata',
                'mesaj' => 'Cihazın IP adresi girilmemiş.',
                'oneri' => 'Cihaz kartından IP adresini girin ya da "Ağda cihaz bul" ile taratın.',
            ];
        }

        return $this->devices->test(ZkConnectionSettings::fromDevice($device));
    }
}
