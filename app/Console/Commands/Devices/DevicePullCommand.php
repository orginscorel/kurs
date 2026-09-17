<?php

namespace App\Console\Commands\Devices;

use App\Exceptions\BusinessRuleException;
use App\Models\Device;
use App\Services\Attendance\ZkPullService;
use App\Services\Devices\Zk\Exceptions\ZkException;
use App\Support\BranchContext;
use Illuminate\Console\Command;

/**
 * Cihazdaki yeni okutmaları çeker ve yoklamaya işler.
 * Kilit (Cache::lock) sayesinde aynı cihaz için iki çekme aynı anda çalışmaz.
 * Dış ağa çıkmaz; yalnız yerel ağdaki cihaza bağlanır.
 */
class DevicePullCommand extends Command
{
    protected $signature = 'kurs:cihaz-cek
        {--device= : Yalnız bu cihazdan çek (cihaz id); boşsa ZK protokollü tüm etkin cihazlar}
        {--tam : İmleci yok say, cihazdaki TÜM kayıtları oku (yinelenenler yazılmaz)}
        {--json : Çıktıyı JSON olarak ver}';

    protected $description = 'Biyometrik terminallerden yeni giriş/çıkış kayıtlarını çeker ve yoklamaya işler';

    public function handle(ZkPullService $pull, BranchContext $context): int
    {
        $devices = $this->devices();

        if ($devices === []) {
            $this->warn('ZK protokolüyle yapılandırılmış etkin cihaz yok. Önce cihazın IP adresini kaydedin (docs/CIHAZ-KOPRUSU.md).');

            return self::SUCCESS;
        }

        $results = [];
        $failed = false;

        foreach ($devices as $device) {
            try {
                $results[] = $context->run($device->branch_id, fn () => $pull->pull($device, (bool) $this->option('tam')));
            } catch (ZkException $e) {
                $failed = true;
                $results[] = ['cihaz' => $device->name, 'durum' => 'hata'] + $e->toArray();
            } catch (BusinessRuleException $e) {
                $failed = true;
                $results[] = ['cihaz' => $device->name, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($results as $result) {
            if (($result['durum'] ?? null) === 'hata') {
                $this->error("✖ {$result['cihaz']}: ".($result['mesaj'] ?? 'bilinmeyen hata'));
                if (! empty($result['oneri'])) {
                    $this->line('   Öneri: '.$result['oneri']);
                }

                continue;
            }

            $this->info("✔ {$result['cihaz']}: {$result['okunan']} kayıt okundu · {$result['islenen']} işlendi · "
                ."{$result['yinelenen']} zaten vardı · {$result['eslesmeyen']} eşleşmedi · {$result['yoksayilan']} yok sayıldı");

            if ($result['eslesmeyen'] > 0) {
                $this->warn('   → Eşleşmeyen okutmalar bekliyor. Eşleme için: php artisan kurs:cihaz-kullanicilar');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<Device> */
    private function devices(): array
    {
        $query = Device::query()->withoutGlobalScope('branch')->where('is_active', true);

        if ($id = $this->option('device')) {
            $device = $query->find((int) $id);

            if (! $device) {
                $this->error("#{$id} numaralı cihaz bulunamadı.");

                return [];
            }

            return [$device];
        }

        return $query->where('protocol', 'zk')->whereNotNull('zk_ip')->get()->all();
    }
}
