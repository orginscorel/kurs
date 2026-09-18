<?php

namespace App\Console\Commands\Devices;

use App\Models\Branch;
use App\Services\Devices\Discovery\DeviceDiscoveryService;
use App\Support\BranchContext;
use Illuminate\Console\Command;

/**
 * Yerel ağda biyometrik terminal arar — kullanıcının cihazın IP'sini bilmesi gerekmez.
 *
 * Dış ağa ÇIKMAZ: yalnız bu makinenin özel (192.168.x / 10.x / 172.16-31.x) ağları taranır.
 * Genel IP blokları hiçbir koşulda taranmaz.
 */
class DeviceScanCommand extends Command
{
    protected $signature = 'kurs:cihaz-tara
        {--ag=* : Taranacak ağ (ör. 192.168.1.0/24). Boşsa bu makinenin ağları kullanılır}
        {--port=4370 : Cihaz portu (fabrika değeri 4370)}
        {--bekleme=300 : Adres başına bekleme, milisaniye (varsayılan 300)}
        {--sure=15 : Toplam tarama süresi üst sınırı, saniye}
        {--sifre= : İletişim şifresi (künye okumak için gerekebilir)}
        {--yayin-yok : UDP yayın keşfini atla, yalnız port süpür}
        {--kunye-yok : Bulunan adreslerin künyesini sorma (yalnız port listesi)}
        {--sube= : Şube kimliği (hangi şubenin kayıtlı cihazlarıyla karşılaştırılsın)}
        {--json : Çıktıyı JSON olarak ver}';

    protected $description = 'Yerel ağdaki biyometrik yoklama terminallerini bulur (ZKTeco / Perkotek YT-33)';

    public function handle(DeviceDiscoveryService $discovery): int
    {
        $branchId = (int) ($this->option('sube') ?: Branch::query()->orderBy('id')->value('id') ?: 0);

        if ($branchId > 0) {
            app(BranchContext::class)->set($branchId);
        }

        $result = $discovery->scan($branchId, [
            'aglar' => (array) $this->option('ag') ?: null,
            'port' => (int) $this->option('port'),
            'zaman_asimi_ms' => (int) $this->option('bekleme'),
            'sure_sn' => (float) $this->option('sure'),
            'yayin' => ! $this->option('yayin-yok'),
            'kunye' => ! $this->option('kunye-yok'),
            'comm_key' => (string) $this->option('sifre') ?: null,
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        return $this->render($result);
    }

    private function render(array $result): int
    {
        $env = $result['ortam'];

        $this->line('Düğüm: '.($env['yerel_dugum'] ? 'yerel (kurumdaki bilgisayar)' : 'web sunucusu'));
        $this->line('Ağlar: '.($env['aglar'] ? implode(', ', $env['aglar']) : '—'));

        if ($env['uyari']) {
            $this->newLine();
            $this->warn($env['uyari']);
        }

        if ($result['durum'] === 'atlandi') {
            $this->newLine();
            $this->error($result['mesaj']);

            return self::FAILURE;
        }

        $ozet = $result['ozet'];
        $this->newLine();
        $this->line(sprintf(
            'Tarandı: %d adres · açık port: %d · yayın yanıtı: %d · süre: %.1f sn',
            $ozet['taranan_adres'], $ozet['acik_port'], $ozet['yayin_yaniti'], $ozet['sure_ms'] / 1000,
        ));

        if ($result['bulunanlar'] === []) {
            $this->newLine();
            $this->warn('Cihaz bulunamadı.');
            $this->line('Kontrol listesi:');
            $this->line('  • Cihazın fişi takılı ve ekranı açık mı?');
            $this->line('  • Cihaz bu bilgisayarla AYNI wifi/ağda mı? (misafir ağı ayrı bir ağdır)');
            $this->line('  • Cihaz menüsü: Comm > PC Bağlantısı > Port 4370 açık mı?');
            $this->line('  • IP biliniyorsa doğrudan deneyin: php artisan kurs:cihaz-test <IP>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['IP', 'Model', 'Seri no', 'Yazılım', 'Kullanıcı', 'Kayıt', 'Durum'],
            array_map(fn (array $d) => [
                $d['ip'].':'.$d['port'],
                $d['model'] ?? '—',
                $d['seri_no'] ?? '—',
                $d['yazilim'] ?? '—',
                $d['kullanici_sayisi'] ?? '—',
                $d['kayit_sayisi'] ?? '—',
                $d['kayitli_mi'] ? 'zaten ekli: '.$d['cihaz_adi'] : ($d['kunye_okundu'] ? 'EKLENEBİLİR' : 'künye okunamadı'),
            ], $result['bulunanlar']),
        );

        foreach ($result['bulunanlar'] as $found) {
            if (! $found['kunye_okundu'] && $found['oneri']) {
                $this->newLine();
                $this->warn($found['ip'].' → '.$found['hata']);
                $this->line('  Öneri: '.$found['oneri']);
            }
        }

        $this->newLine();
        $this->info('Eklemek için: Yoklama > Cihazlar > "Ağda cihaz bul" ekranından tek tıkla ekleyin.');

        return self::SUCCESS;
    }
}
