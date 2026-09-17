<?php

namespace App\Console\Commands\Devices;

use App\Services\Devices\Zk\Exceptions\ZkException;
use App\Services\Devices\Zk\ZkConnectionSettings;
use App\Services\Devices\Zk\ZkTerminal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Cihaz bağlantı testi — kullanıcının Mac'inde İLK denemede sorunu anlatan çıktı.
 * Dış ağa çıkmaz; yalnız verilen yerel IP'ye bağlanır.
 */
class DeviceTestCommand extends Command
{
    protected $signature = 'kurs:cihaz-test
        {ip : Cihazın yerel ağdaki IP adresi (ör. 192.168.1.50)}
        {--port=4370 : Cihaz portu (fabrika değeri 4370)}
        {--sifre= : İletişim şifresi (comm key); cihazda kapalıysa boş bırakın}
        {--aktarim=tcp : tcp veya udp}
        {--zaman-asimi=10 : Okuma zaman aşımı (saniye)}
        {--saati-esitle : Cihaz saatini bu bilgisayarın saatine eşitler}
        {--json : Çıktıyı JSON olarak ver}';

    protected $description = 'Biyometrik terminale bağlanır; künye, saat ve son 5 okutmayı gösterir (Perkotek YT-33 / ZKTeco)';

    public function handle(): int
    {
        $settings = ZkConnectionSettings::fromArray([
            'host' => (string) $this->argument('ip'),
            'port' => (int) $this->option('port'),
            'transport' => (string) $this->option('aktarim'),
            'comm_key' => (string) $this->option('sifre'),
            'read_timeout' => (float) $this->option('zaman-asimi'),
        ]);

        $json = (bool) $this->option('json');

        if (! $json) {
            $this->line("Bağlanılıyor: {$settings->label()} …");
        }

        $terminal = null;

        try {
            $terminal = ZkTerminal::open($settings);
            $info = $terminal->info();

            if ($this->option('saati-esitle')) {
                $terminal->setTime(CarbonImmutable::now());
            }

            $records = $terminal->attendance(CarbonImmutable::now()->subDays(30), 2000);
            $last = array_slice($records, -5);
            $users = $terminal->userCountSafe();
        } catch (ZkException $e) {
            return $this->reportFailure($e, $json);
        } finally {
            $terminal?->close();
        }

        $payload = [
            'durum' => 'ok',
            'hedef' => $settings->label(),
            'cihaz' => $info->toArray(),
            'cihaz_saati_farki_saniye' => $info->deviceTime ? CarbonImmutable::parse($info->deviceTime)->diffInSeconds(CarbonImmutable::now(), true) : null,
            'son_kayitlar' => array_map(fn ($r) => $r->toArray(), $last),
            'okunan_kayit' => count($records),
            'cihazdaki_kullanici' => $users,
        ];

        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('✔ Bağlantı başarılı.');
        $this->newLine();
        $this->table(['Bilgi', 'Değer'], collect($info->toArray())
            ->reject(fn ($v) => $v === null)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), (string) $v])->values()->all());

        $drift = $payload['cihaz_saati_farki_saniye'];
        if ($drift !== null && $drift > 60) {
            $this->warn("⚠ Cihaz saati bilgisayardan {$drift} saniye farklı. Giriş/çıkış saatleri kayar. Düzeltmek için: --saati-esitle");
        }

        $this->newLine();
        $this->line('Son 30 günde okunan kayıt: '.count($records).' · Cihazdaki kullanıcı: '.($users ?? 'okunamadı'));

        if ($last === []) {
            $this->warn('Cihazda hiç okutma kaydı yok. Cihaza bir parmak okutup komutu tekrar çalıştırın.');
        } else {
            $this->newLine();
            $this->line('Son 5 okutma:');
            $this->table(['Kullanıcı no', 'Zaman', 'Punch', 'Doğrulama'], array_map(
                fn ($r) => [$r->userId, $r->timestamp->format('d.m.Y H:i:s'), $r->status, $r->verify],
                $last,
            ));
        }

        $this->newLine();
        $this->line('Sonraki adım: bu kullanıcı numaralarını öğrencilerle eşleştirin → php artisan kurs:cihaz-kullanicilar --device=<id>');

        return self::SUCCESS;
    }

    private function reportFailure(ZkException $e, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode(['durum' => 'hata'] + $e->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $this->newLine();
        $this->error('✖ '.$e->getMessage());
        if ($e->hint !== '') {
            $this->newLine();
            $this->line('Öneri: '.$e->hint);
        }
        $this->newLine();
        $this->line('Ayrıntılı kurulum ve sorun giderme: docs/CIHAZ-KOPRUSU.md');

        return self::FAILURE;
    }
}
