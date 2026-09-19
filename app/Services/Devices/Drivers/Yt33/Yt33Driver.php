<?php

namespace App\Services\Devices\Drivers\Yt33;

use App\Services\Devices\Drivers\AbstractTerminalDriver;
use App\Services\Devices\Terminal\DriverResult;
use App\Services\Devices\Terminal\TerminalEndpoint;
use Carbon\CarbonImmutable;

/**
 * Perkotek YT33 — web arayüzü "Dynamic Face", cihaz adı "fk_device" (FK ailesi).
 *
 * PROTOKOL UYDURMA YOK: paket yapısı için kod tabanında SDK (FKAttend DLL/dylib) ya da üretici belgesi yok.
 * Bu sürücü cihaza HİÇBİR uygulama paketi göndermez (ZKTeco paketleri dahil). Çalışan kısım:
 *   • ağ/soket sınaması (TcpProbe), ham TCP oturumu + RAW log + manuel HEX gönderici (geliştirici modu),
 *   • push dinleyicisi (Push\Server) ve protokol analizi aracı.
 * Protokol yöntemleri ProtocolNotImplementedError fırlatır; TerminalDriver yöntemleri tipli
 * DriverStatus::ProtocolNotImplemented döner ("Protokol verisi bekleniyor").
 */
class Yt33Driver extends AbstractTerminalDriver implements Protocol
{
    private const HINT = 'Ağ katmanı çalışıyor; bu bir ağ arızası değildir. YT33 veri protokolü, cihaz ile üretici yazılımı arasındaki gerçek trafik kaydıyla doğrulanınca kayıt çekme etkinleşecek. '
        .'O zamana kadar Terminal Teşhis › RAW TCP log ve Push dinleyicisi ile cihazın gönderdiği baytlar kaydedilebilir.';

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
        return ['tarama' => false, 'cekme' => false, 'itme' => true, 'kullanicilar' => false, 'ag_testi' => true, 'ham_tani' => true];
    }

    public function defaultPort(): ?int
    {
        return Types::DEFAULT_PULL_PORT;
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
            ['ad' => 'port', 'etiket' => 'Port', 'tur' => 'sayi', 'varsayilan' => Types::DEFAULT_PULL_PORT],
            ['ad' => 'makine_id', 'etiket' => 'Cihaz / Machine ID', 'tur' => 'sayi', 'varsayilan' => Types::DEFAULT_MACHINE_ID],
            ['ad' => 'comm_key', 'etiket' => 'İletişim şifresi', 'tur' => 'gizli', 'varsayilan' => Types::DEFAULT_COMM_KEY, 'ipucu' => 'Yalnız rakam; web arayüzünün admin şifresi DEĞİLDİR.'],
            $this->directionField(),
        ];
    }

    public function setupSteps(): array
    {
        return [
            'Cihazın IP adresini sabitleyin (web arayüzü › ağ ayarları); DHCP ile değişen IP bağlantıyı koparır.',
            'Bu Mac ile cihaz aynı yerel ağda olmalı (misafir ağı olmaz).',
            'macOS ilk bağlantıda "Erbaa Kurs yerel ağdaki aygıtları bulmak istiyor" diye sorar → İzin Ver (Sistem Ayarları › Gizlilik ve Güvenlik › Yerel Ağ).',
            '"Bağlantıyı test et": Ağ ve TCP aşamaları başarılı olmalı. Protokol aşaması "Doğrulama bekliyor" görünür — bu bir ağ arızası değildir.',
        ];
    }

    // ------------------------------------------------ TerminalDriver (tipli sonuç, istisna yok)

    public function connect(TerminalEndpoint $endpoint): DriverResult
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('bağlan (el sıkışma)');
    }

    public function identifyDevice(TerminalEndpoint $endpoint): DriverResult
    {
        return $this->waiting('cihaz tanıma');
    }

    public function fetchUsers(TerminalEndpoint $endpoint, int $deviceId): DriverResult
    {
        return $this->waiting('kullanıcı listesi');
    }

    public function fetchAttendanceLogs(TerminalEndpoint $endpoint, int $deviceId, ?CarbonImmutable $since = null): DriverResult
    {
        return $this->waiting('yoklama kayıtları');
    }

    // ------------------------------------------------ Protocol (doğrulanmamış → istisna)

    public function disconnect(): void
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('bağlantıyı kapat');
    }

    public function probe(): array
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('protokol yoklaması');
    }

    public function getDeviceInfo(): array
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('cihaz bilgisi');
    }

    public function getUsers(): array
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('kullanıcı listesi');
    }

    public function getAttendanceLogs(?CarbonImmutable $since = null): array
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('yoklama kayıtları');
    }

    public function setUser(string $deviceUserId, string $name, ?string $cardNo = null): void
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('kullanıcı ekle/güncelle');
    }

    public function deleteUser(string $deviceUserId): void
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('kullanıcı sil');
    }

    public function syncTime(CarbonImmutable $time): void
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('saat eşitle');
    }

    public function listenEvents(callable $onEvent, float $seconds): void
    {
        // Waiting for verified YT33 packet capture
        throw new ProtocolNotImplementedError('olay dinleme');
    }

    private function waiting(string $operation): DriverResult
    {
        return DriverResult::notImplemented(Types::WAITING.': YT33 uygulama protokolü henüz doğrulanmadı ('.$operation.').', self::HINT);
    }
}
