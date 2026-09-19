<?php

namespace App\Services\Attendance;

use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Services\Devices\Drivers\DriverRegistry;
use Illuminate\Support\Facades\DB;

/**
 * CİHAZ TEŞHİSİ — "bağlı mı, en son ne zaman kayıt geldi, kaç kayıt bekliyor, sorun neyse
 * cihaz menüsünde ne yapmalıyım?"
 *
 * Tasarım kararı: teşhis CİHAZA BAĞLANMAZ. Yalnız veritabanındaki izlerden (son çekme sonucu,
 * son olay, eşleşmemiş kayıtlar) okur. Böylece ekran anında açılır ve web sunucusunda da
 * çalışır — cihaza ulaşamamak teşhisi engellemez, teşhisin kendisi zaten bunu söyler.
 *
 * Her sorun için ÇÖZÜM ÖNERİSİ Türkçedir ve kullanıcıya cihaz menüsünde ne yapacağını söyler.
 */
class DeviceDiagnostics
{
    public function __construct(private readonly DriverRegistry $drivers) {}

    /** Şubedeki tüm terminaller için teşhis satırları. */
    public function forBranch(int $branchId): array
    {
        $devices = Device::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->orderBy('name')->get();

        $stats = DB::table('attendance_events')
            ->where('branch_id', $branchId)->whereNotNull('device_id')
            ->selectRaw('device_id, MAX(occurred_at) AS son_olay, COUNT(*) AS toplam, SUM(CASE WHEN is_matched = 0 THEN 1 ELSE 0 END) AS bekleyen')
            ->groupBy('device_id')->get()->keyBy('device_id');

        return $devices->map(fn (Device $device) => $this->describe($device, $stats[$device->id] ?? null))->values()->all();
    }

    public function forDevice(Device $device): array
    {
        $row = DB::table('attendance_events')
            ->where('branch_id', $device->branch_id)->where('device_id', $device->id)
            ->selectRaw('MAX(occurred_at) AS son_olay, COUNT(*) AS toplam, SUM(CASE WHEN is_matched = 0 THEN 1 ELSE 0 END) AS bekleyen')
            ->first();

        return $this->describe($device, $row);
    }

    private function describe(Device $device, ?object $stats): array
    {
        $driver = $this->drivers->for($device);
        $health = $this->health($device, $stats);

        return [
            'id' => $device->id,
            'ad' => $device->name,
            'tur' => $device->kind,
            'konum' => $device->location,
            'aktif' => (bool) $device->is_active,
            'protokol' => $device->protocol ?: 'zk',
            'protokol_etiketi' => $driver->label(),
            'protokol_durumu' => $driver->status(),
            'yetenekler' => $driver->capabilities(),
            'ip' => $device->zk_ip,
            'port' => $device->zk_port,
            'aktarim' => $device->zk_transport,
            'seri_no' => $device->serial_no,
            'model' => $device->device_model,
            'marka' => $device->vendor,
            'yazilim' => $device->firmware,
            'sifre_tanimli' => $device->zk_comm_key !== null,

            'durum' => $health['durum'],           // ok | uyari | hata | kurulmadi
            'durum_metni' => $health['metin'],
            'cozum' => $health['cozum'],

            'son_gorulme' => $device->last_seen_at,
            'son_cekme' => $device->zk_last_pull_at,
            'son_cekme_sonucu' => $device->zk_last_status,
            'son_hata' => $device->zk_last_error,
            'son_okunan_kayit' => (int) $device->zk_last_record_count,
            'imlec' => $device->zk_cursor_at,

            'son_olay' => $stats->son_olay ?? null,
            'toplam_olay' => (int) ($stats->toplam ?? 0),
            'bekleyen_kayit' => (int) ($stats->bekleyen ?? 0),
        ];
    }

    /**
     * Cihazın sağlık durumu + ÇÖZÜM. Sıra önemlidir: en engelleyici sorun önce söylenir.
     *
     * @return array{durum:string, metin:string, cozum:?string}
     */
    private function health(Device $device, ?object $stats): array
    {
        $protocol = $device->protocol ?: 'zk';

        if (! $device->is_active) {
            return ['durum' => 'uyari', 'metin' => 'Cihaz pasif', 'cozum' => 'Cihaz kartından "Düzenle" > Durum: Aktif yapın.'];
        }

        // 1) Kurulum tamamlanmamış
        if (in_array($protocol, ['zk', 'perkotek_fk', 'generic_tcp'], true) && ! $device->zk_ip) {
            return [
                'durum' => 'kurulmadi',
                'metin' => 'IP adresi girilmemiş',
                'cozum' => '"Ağda cihaz bul" ile taratın ya da cihaz menüsündeki IP adresini (Comm > Ethernet) elle girin.',
            ];
        }

        if ($protocol === 'adms' && ! $device->serial_no) {
            return [
                'durum' => 'kurulmadi',
                'metin' => 'Seri numarası girilmemiş',
                'cozum' => 'Cihaz menüsü: Sistem Bilgisi > Cihaz Bilgisi ekranındaki seri numarasını cihaz kartına yazın.',
            ];
        }

        if (in_array($protocol, ['hikvision', 'anviz'], true)) {
            return [
                'durum' => 'uyari',
                'metin' => 'Bu marka için bağlantı katmanı henüz yazılmadı',
                'cozum' => 'Cihaz kaydı duruyor ama kayıt çekilemez. ZKTeco/Perkotek uyumlu bir terminal ya da ADMS destekli model kullanın.',
            ];
        }

        if ($protocol === 'perkotek_fk') {
            return [
                'durum' => 'uyari',
                'metin' => 'Perkotek YT33 / FK protokolü henüz doğrulanmadı',
                'cozum' => 'Ağ bağlantısı Terminal Köprüsü › "Bağlantıyı test et" ile sınanabilir; kayıt çekme protokol doğrulanınca etkinleşecek.',
            ];
        }

        if ($protocol === 'generic_tcp') {
            return ['durum' => 'uyari', 'metin' => 'Genel TCP sürücüsü: yalnız ağ testi', 'cozum' => 'Cihaza uygun sürücüyü seçin.'];
        }

        // 2) Son denemede hata
        if ($device->zk_last_status === 'error') {
            return [
                'durum' => 'hata',
                'metin' => 'Son bağlantı denemesi başarısız',
                'cozum' => $this->remedy((string) $device->zk_last_error, $protocol),
            ];
        }

        // 3) Hiç veri gelmemiş
        if ($device->zk_last_pull_at === null && $device->last_seen_at === null) {
            return [
                'durum' => 'kurulmadi',
                'metin' => 'Cihazla henüz hiç konuşulmadı',
                'cozum' => $protocol === 'adms'
                    ? 'Cihaz menüsü: Comm > ADMS → sunucu adresi olarak bu bilgisayarın IP adresini ve port 80 girin, cihazı yeniden başlatın.'
                    : '"Bağlantıyı test et" düğmesine basın. Bu ekran kurumdaki bilgisayardan açılmalıdır; web sunucusu cihazın ağına giremez.',
            ];
        }

        // 4) Sessizleşme: bağlantı var ama uzun süredir kayıt yok
        $lastEvent = $stats->son_olay ?? null;
        $hoursSinceEvent = $lastEvent ? now()->diffInHours($lastEvent, true) : null;

        if ($hoursSinceEvent !== null && $hoursSinceEvent > 48) {
            return [
                'durum' => 'uyari',
                'metin' => 'Bağlantı var ama 2 günden uzun süredir okutma gelmiyor',
                'cozum' => 'Cihazda öğrenciler parmak okutuyor mu? Cihaz saati doğru mu (Sistem > Tarih/Saat)? Cihazın kayıt belleği silinmiş olabilir.',
            ];
        }

        $pending = (int) ($stats->bekleyen ?? 0);

        if ($pending > 0) {
            return [
                'durum' => 'uyari',
                'metin' => "{$pending} okutma hiçbir öğrenciye bağlanamadı",
                'cozum' => 'Terminal Köprüsü > Cihaz kullanıcıları ekranından kullanıcı numaralarını öğrencilerle eşleyin. Eşlenmeyen okutmalar kaybolmaz, bekler.',
            ];
        }

        return ['durum' => 'ok', 'metin' => 'Cihaz çalışıyor', 'cozum' => null];
    }

    /**
     * Son hatadan çözüm önerisi üretir. Hata metni "[kod] mesaj" biçiminde saklanır
     * (ZkPullService::markFailure); kod, köprü istisnalarının code() değeridir.
     */
    private function remedy(string $error, string $protocol): string
    {
        $code = preg_match('/^\[([a-z_]+)\]/', $error, $m) ? $m[1] : '';

        return match ($code) {
            'kimlik' => 'Cihazın iletişim şifresi yanlış. Cihaz menüsü: Comm (İletişim) > İletişim Şifresi. Oradaki sayıyı cihaz kartındaki "İletişim şifresi" alanına yazın; şifre kapalıysa cihazda 0 yapın.',
            'baglanti' => 'Soket açılamadı. Sırayla kontrol edin: (1) cihazın fişi takılı ve ekranı açık mı, (2) ağ kablosu/wifi bağlı mı, (3) cihazdaki IP hâlâ aynı mı (DHCP kapalı, sabit IP), (4) bu bilgisayar cihazla aynı ağda mı — misafir ağı olmaz, (5) Mac\'te: Sistem Ayarları › Gizlilik ve Güvenlik › Yerel Ağ › Erbaa Kurs açık mı, (6) cihazın kendi programı açıksa kapatın, cihaz tek bağlantı kabul edebilir.',
            'zaman_asimi' => 'Soket açıldı ama cihaz süre içinde yanıt vermedi. Seçili sürücünün cihazla uyumlu olduğunu doğrulayın; cihazın kendi programını kapatıp birkaç saniye sonra tekrar deneyin.',
            'protokol' => 'TCP bağlandı fakat cihaz protokolü beklenen yanıtı vermedi. Seçili sürücünün cihazla uyumlu olduğunu doğrulayın (ör. Perkotek YT33 / FK Dynamic Face cihazları ZKTeco protokolü konuşmaz); ZKTeco cihazda bağlantı türünü UDP yapıp tekrar deneyin.',
            default => $protocol === 'adms'
                ? 'Cihaz kendi kayıtlarını gönderemedi. Comm > ADMS ayarlarındaki sunucu adresi ve port hâlâ doğru mu? Cihazı yeniden başlatın.'
                : 'Cihaz kartından "Bağlantıyı test et" düğmesine basın; çıkan mesaj ne yapılacağını yazar.',
        };
    }
}
