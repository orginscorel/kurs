<?php

namespace App\Console\Commands\Devices;

use App\Models\Device;
use App\Services\Attendance\TerminalEnrollmentService;
use App\Services\Devices\Terminal\Data\DeviceUser;
use App\Services\Devices\WebPanel\WebPanelDriver;
use Illuminate\Console\Command;

/**
 * GÜVENLİ CANLI DENEME — cihaz web paneline yazma yolunu tek, atılabilir bir numarayla doğrular.
 * Akış: künye oku → numara boş mu bak → ekle (SetUserInfo) → GetUserInfo ile doğrula → sil (DeleteUserInfo) → gitti mi bak.
 * Numara zaten doluysa DOKUNMAZ ve çıkar (birinin gerçek kaydını ezmemek/silmemek için). Yalnız yerel düğüm.
 */
class WebPanelProbeCommand extends Command
{
    protected $signature = 'kurs:cihaz-panel-dene {--device= : Cihaz id (boşsa şubenin panel cihazı)} {--no=9001 : Deneme numarası} {--ad=KURS TEST : Deneme adı}';

    protected $description = 'Cihaz web paneline yazmayı güvenli, atılabilir bir numarayla dener (ekle → doğrula → sil)';

    public function handle(WebPanelDriver $panel, TerminalEnrollmentService $enroll): int
    {
        if (config('kurs.node') !== 'local') {
            $this->error('Bu deneme yalnız cihazın bulunduğu ağdaki Mac uygulamasında (yerel düğüm) çalışır.');

            return self::FAILURE;
        }

        $device = $this->option('device')
            ? Device::query()->withoutGlobalScopes()->find((int) $this->option('device'))
            : Device::query()->withoutGlobalScopes()->where('is_active', true)->whereNull('deleted_at')
                ->whereNotNull('panel_user')->whereNotNull('panel_password_encrypted')->orderBy('id')->get()
                ->first(fn (Device $d) => $d->supportsWebPanel());

        if (! $device || ! $device->supportsWebPanel()) {
            $this->error('Web paneli ayarlı cihaz bulunamadı. Önce PDKS › Cihaz işlemleri › Web paneli ayarını (IP + kullanıcı + şifre) kaydedin.');

            return self::FAILURE;
        }

        $no = trim((string) $this->option('no'));
        $ad = (string) $this->option('ad');
        $this->info("Cihaz: {$device->name} ({$device->zk_ip}) · deneme no: {$no}");

        // 1) Künye — bağlantı + kimlik doğru mu (cihazı değiştirmez)
        $info = $panel->deviceInfo($device);
        if (! $info->isOk()) {
            $this->error("1/5 Bağlantı/kimlik: {$info->message}".($info->hint ? " — {$info->hint}" : ''));

            return self::FAILURE;
        }
        $rd = is_array($info->data) ? $info->data : [];
        $this->line("1/5 Bağlantı OK · cihaz \"".($rd['name'] ?? '?')."\" · kullanıcı sayısı ".($rd['userCount'] ?? '?'));

        // 2) Numara boş mu? Doluysa DOKUNMA.
        $before = $panel->getUser($device, $no);
        if (! $before->isOk()) {
            $this->error("2/5 Numara kontrolü: {$before->message}");

            return self::FAILURE;
        }
        if ($before->data instanceof DeviceUser) {
            $this->error("2/5 {$no} numarası cihazda ZATEN dolu (\"{$before->data->name}\"). Güvenlik için hiçbir şey değiştirilmedi. Boş bir --no verin.");

            return self::FAILURE;
        }
        $this->line("2/5 {$no} numarası boş — deneme güvenli.");

        // 3) Ekle
        $set = $panel->upsertUser($device, $no, $ad);
        if (! $set->isOk()) {
            $this->error("3/5 Ekleme (SetUserInfo): {$set->message}".($set->hint ? " — {$set->hint}" : ''));

            return self::FAILURE;
        }
        $this->line("3/5 Eklendi (SetUserInfo) · {$no} = \"{$ad}\"");

        // 4) Doğrula
        $check = $panel->getUser($device, $no);
        $ok = $check->isOk() && $check->data instanceof DeviceUser && $check->data->deviceUserId === $no;
        $this->line('4/5 Doğrulama (GetUserInfo): '.($ok ? "cihazda görünüyor → \"{$check->data->name}\"" : 'BULUNAMADI (cihaz kabul etmemiş olabilir)'));

        // 5) Sil (denemeyi temizle) — başarısız doğrulamada bile temizlemeyi dener
        $del = $panel->deleteUsers($device, [$no]);
        if (! $del->isOk()) {
            $this->error("5/5 Silme (DeleteUserInfo): {$del->message} — {$no} numarasını cihaz menüsünden elle silin!");

            return self::FAILURE;
        }
        $gone = $panel->getUser($device, $no);
        $cleaned = $gone->isOk() && ! ($gone->data instanceof DeviceUser);
        $this->line('5/5 Silindi (DeleteUserInfo)'.($cleaned ? ' ve cihazda artık yok.' : ' (silme sonrası hâlâ görünüyorsa cihazı kontrol edin).'));

        if ($ok && $cleaned) {
            $this->info('✓ Deneme başarılı: cihaza yazma, okuma ve silme çalışıyor. Artık "Terminale kaydet" sihirbazından gerçek kişilerde kullanabilirsiniz.');

            return self::SUCCESS;
        }

        $this->warn('Deneme tamamlandı ama ekleme/doğrulama beklendiği gibi değildi; yukarıdaki adımları kontrol edin.');

        return self::FAILURE;
    }
}
