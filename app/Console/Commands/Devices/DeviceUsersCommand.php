<?php

namespace App\Console\Commands\Devices;

use App\Exceptions\BusinessRuleException;
use App\Models\Device;
use App\Services\Attendance\ZkDeviceService;
use App\Services\Devices\Zk\Exceptions\ZkException;
use App\Support\BranchContext;
use Illuminate\Console\Command;

/**
 * Cihazdaki kullanıcıları listeler ve hangi öğrenciye ait olabileceğini ÖNERİR.
 * Otomatik eşleme YAPMAZ: yanlış eşleme, yanlış velinin telefonuna bildirim gider.
 */
class DeviceUsersCommand extends Command
{
    protected $signature = 'kurs:cihaz-kullanicilar
        {--device= : Cihaz id (zorunlu)}
        {--json : Çıktıyı JSON olarak ver}';

    protected $description = 'Biyometrik terminaldeki kullanıcıları listeler ve öğrenci eşleştirme önerisi verir';

    public function handle(ZkDeviceService $devices, BranchContext $context): int
    {
        $id = (int) $this->option('device');
        $device = $id ? Device::query()->withoutGlobalScope('branch')->find($id) : null;

        if (! $device) {
            $this->error('Cihaz bulunamadı. Kullanım: php artisan kurs:cihaz-kullanicilar --device=<id>');
            $this->line('Cihaz listesi: Yoklama > Cihazlar ekranı.');

            return self::FAILURE;
        }

        try {
            $rows = $context->run($device->branch_id, fn () => $devices->usersWithSuggestions($device));
        } catch (ZkException $e) {
            $this->error('✖ '.$e->getMessage());
            if ($e->hint !== '') {
                $this->line('Öneri: '.$e->hint);
            }

            return self::FAILURE;
        } catch (BusinessRuleException $e) {
            $this->error('✖ '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warn('Cihazda hiç kullanıcı yok. Önce cihazda öğrencilerin parmak izini kaydedin.');

            return self::SUCCESS;
        }

        $this->table(
            ['Kullanıcı no', 'Cihazdaki ad', 'Yetki', 'Eşleşen öğrenci', 'Öneri'],
            array_map(fn (array $r) => [
                $r['kullanici_no'],
                $r['ad'],
                $r['yetki'],
                $r['eslesen_ogrenci']['ad'] ?? '—',
                $r['eslesen_ogrenci'] ? '' : implode(' | ', array_map(fn ($s) => "{$s['ad']} (%{$s['benzerlik']})", $r['oneriler'])),
            ], $rows),
        );

        $unmatched = count(array_filter($rows, fn ($r) => $r['eslesen_ogrenci'] === null));
        $this->newLine();
        $this->line('Toplam '.count($rows)." kullanıcı · eşleşmeyen: {$unmatched}");

        if ($unmatched > 0) {
            $this->line('Eşlemeyi ekrandan (Yoklama > Cihazlar) ya da POST /api/v1/attendance/zk/eslemeler ile tamamlayın.');
        }

        return self::SUCCESS;
    }
}
