<?php

namespace App\Services\Devices\Drivers;

use App\Models\Device;

/**
 * ADMS / iclock — cihaz KENDİSİ sunucuya HTTP POST atar (push).
 *
 * Neden gerekli? Yeni aygıt yazılımlarının bir kısmı 4370 portunu kapatıyor ya da "yalnız
 * sunucuya bağlan" kipine geçiyor. O cihazlarda ZKTeco protokolüyle biz bağlanamayız; cihaz
 * bize bağlanır. Aynı ağda olmamız bile gerekmez — cihazın ulaşabildiği bir adres yeterlidir.
 *
 * Cihaz menüsü: Comm > ADMS (bazı modellerde "Sunucu Ayarları" / "Cloud Server").
 */
class AdmsDriver implements DeviceDriver
{
    public function key(): string
    {
        return 'adms';
    }

    public function label(): string
    {
        return 'ADMS / iclock (cihaz kendisi gönderir)';
    }

    public function vendors(): array
    {
        return ['Perkotek (ADMS destekli modeller)', 'ZKTeco (Push SDK)', '4370 portu kapalı yeni aygıt yazılımları'];
    }

    public function status(): string
    {
        return 'hazir';
    }

    public function capabilities(): array
    {
        return ['tarama' => false, 'cekme' => false, 'itme' => true, 'kullanicilar' => false];
    }

    public function fields(): array
    {
        return [
            ['ad' => 'serial_no', 'etiket' => 'Cihaz seri numarası (SN)', 'tur' => 'metin', 'zorunlu' => true,
                'ipucu' => 'Cihaz menüsü: Sistem Bilgisi > Cihaz Bilgisi. Cihaz kendini bu numarayla tanıtır.'],
            ['ad' => 'direction', 'etiket' => 'Kapı yönü', 'tur' => 'secim', 'varsayilan' => 'both',
                'secenekler' => [['deger' => 'both', 'etiket' => 'Giriş + Çıkış'], ['deger' => 'entry', 'etiket' => 'Yalnız giriş'], ['deger' => 'exit', 'etiket' => 'Yalnız çıkış']]],
        ];
    }

    public function setupSteps(): array
    {
        return [
            'Cihaz menüsü: Comm (İletişim) > ADMS / Sunucu Ayarları.',
            'Sunucu adresi: bu bilgisayarın yerel IP adresi (ekranda yazar). Port: 80.',
            'Adres biçimi "HTTP" olmalı; "Proxy" ve "DNS ile bağlan" kapalı.',
            'Kaydedip cihazı yeniden başlatın; cihaz birkaç saniye içinde kendini tanıtır.',
            'Cihaz listede "tanıtıldı" görünmüyorsa: cihaz ile bilgisayar aynı ağda mı, güvenlik duvarı 80 portunu açıyor mu?',
        ];
    }

    public function test(Device $device): array
    {
        if (! $device->serial_no) {
            return [
                'durum' => 'hata',
                'mesaj' => 'Cihazın seri numarası girilmemiş.',
                'oneri' => 'Cihaz menüsü: Sistem Bilgisi > Cihaz Bilgisi ekranındaki seri numarasını cihaz kartına yazın.',
            ];
        }

        if (! $device->last_seen_at) {
            return [
                'durum' => 'hata',
                'mesaj' => 'Cihaz henüz hiç bağlanmadı.',
                'oneri' => 'Cihaz menüsünden ADMS sunucu adresini bu bilgisayarın IP adresi olarak girin ve cihazı yeniden başlatın.',
            ];
        }

        $minutes = (int) $device->last_seen_at->diffInMinutes(now());

        if ($minutes > 10) {
            return [
                'durum' => 'hata',
                'mesaj' => "Cihaz {$minutes} dakikadır bağlanmadı.",
                'oneri' => 'Cihazın fişi takılı ve ağa bağlı mı? ADMS sunucu adresi hâlâ doğru mu? (Comm > ADMS)',
            ];
        }

        return [
            'durum' => 'ok',
            'mesaj' => 'Cihaz düzenli bağlanıyor.',
            'cihaz' => ['seri_no' => $device->serial_no, 'yazilim' => $device->firmware, 'model' => $device->device_model],
        ];
    }
}
