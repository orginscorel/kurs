<?php

namespace App\Services\Devices\Drivers;

use App\Services\Devices\Terminal\DriverResult;
use App\Services\Devices\Terminal\TerminalEndpoint;
use Carbon\CarbonImmutable;

/**
 * Perkotek YT33 — web arayüzü başlığı "Dynamic Face", cihaz adı "fk_device" (FK ailesi).
 *
 * PROTOKOL UYDURMA YOK: Bu cihazın PC bağlantısı (ör. TCP 5005) paket yapısı için kod tabanında ne bir SDK
 * (FKAttend DLL/dylib) ne de üretici belgesi var. Paket biçimini TAHMİN ETMİYORUZ, ZKTeco paketlerini bu
 * cihaza GÖNDERMİYORUZ. Bu yüzden:
 *   • İletişim katmanı tam çalışır: soket sınaması, süre, yerel IP, errno → Türkçe neden.
 *   • Ham TCP tanılaması (Terminal Teşhis) ile cihazın bağlantıda ne gönderdiği HEX/ASCII görülebilir.
 *   • Kimlik/kullanıcı/kayıt çekme → DriverStatus::ProtocolNotImplemented ("protokol henüz doğrulanmadı").
 * Protokol üreticiden/SDK'dan doğrulandığında yalnız bu sınıf doldurulur; ekran ve boru hattı değişmez.
 */
class PerkotekYT33Driver extends AbstractTerminalDriver
{
    private const NOT_VERIFIED = 'Perkotek YT33 / FK Dynamic Face protokolü henüz doğrulanmadı.';

    private const NOT_VERIFIED_HINT = 'Ağ katmanı çalışıyorsa (TCP bağlantısı açılıyorsa) sorun ağda değildir. Bu cihazın veri protokolü üretici belgesi/SDK ile doğrulanınca kayıt çekme etkinleşecek. '
        .'O zamana kadar Terminal Teşhis › Ham TCP tanılaması ile cihazın yanıtlarını kaydedebilirsiniz; cihazın kendi kayıt gönderme (Push) özelliği de ileride bu köprüye yönlendirilebilecek.';

    public function key(): string
    {
        return 'perkotek_fk';
    }

    public function label(): string
    {
        return 'Perkotek YT33 / FK Dynamic Face';
    }

    public function vendors(): array
    {
        return ['Perkotek YT33', 'FK / "Dynamic Face" web arayüzlü yüz tanıma terminalleri'];
    }

    public function status(): string
    {
        return 'kismi';
    }

    public function capabilities(): array
    {
        return ['tarama' => false, 'cekme' => false, 'itme' => false, 'kullanicilar' => false, 'ag_testi' => true, 'ham_tani' => true];
    }

    public function defaultPort(): ?int
    {
        return 5005;
    }

    public function transports(): array
    {
        return ['tcp'];
    }

    public function protocolVerified(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            ['ad' => 'ip', 'etiket' => 'IP adresi', 'tur' => 'ip', 'zorunlu' => true, 'ipucu' => 'Cihazın web arayüzünde (Dynamic Face) görünen IP adresi.'],
            ['ad' => 'port', 'etiket' => 'Port', 'tur' => 'sayi', 'varsayilan' => 5005, 'ipucu' => 'Cihazın PC bağlantı portu (YT33\'te genellikle 5005).'],
            ['ad' => 'comm_key', 'etiket' => 'İletişim şifresi', 'tur' => 'gizli', 'ipucu' => 'Yalnız rakam; cihaz web arayüzünün admin şifresi DEĞİLDİR.'],
            $this->directionField(),
        ];
    }

    public function setupSteps(): array
    {
        return [
            'Cihazın IP adresini sabitleyin (web arayüzü › ağ ayarları); DHCP ile değişen IP bağlantıyı koparır.',
            'Bu Mac ile cihaz aynı yerel ağda olmalı (misafir ağı olmaz).',
            'macOS ilk bağlantıda "Erbaa Kurs yerel ağdaki aygıtları bulmak istiyor" diye sorar → İzin Ver. Sorulmadıysa: Sistem Ayarları › Gizlilik ve Güvenlik › Yerel Ağ › Erbaa Kurs açık.',
            '"Bağlantıyı test et": Ağ/TCP aşaması başarılı olmalı. Protokol aşaması şimdilik "henüz doğrulanmadı" görünür — bu beklenen durumdur.',
        ];
    }

    public function connect(TerminalEndpoint $endpoint): DriverResult
    {
        return DriverResult::notImplemented(self::NOT_VERIFIED, self::NOT_VERIFIED_HINT);
    }

    public function identifyDevice(TerminalEndpoint $endpoint): DriverResult
    {
        return DriverResult::notImplemented(self::NOT_VERIFIED, self::NOT_VERIFIED_HINT);
    }

    public function fetchUsers(TerminalEndpoint $endpoint, int $deviceId): DriverResult
    {
        return DriverResult::notImplemented(self::NOT_VERIFIED.' Cihaz kullanıcıları okunamaz.', self::NOT_VERIFIED_HINT);
    }

    public function fetchAttendanceLogs(TerminalEndpoint $endpoint, int $deviceId, ?CarbonImmutable $since = null): DriverResult
    {
        return DriverResult::notImplemented(self::NOT_VERIFIED.' Kayıt çekme henüz yapılamıyor.', self::NOT_VERIFIED_HINT);
    }
}
