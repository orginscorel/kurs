<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Console\Command;

/** Toplu SMS teslim raporlarını sağlayıcıdan çeker (gönderildi → iletildi / başarısız). */
class SyncCampaignDeliveryReports extends Command
{
    protected $signature = 'kurs:campaigns-sync-reports';

    protected $description = 'Toplu SMS teslim raporlarını sağlayıcıdan günceller';

    public function handle(CampaignSender $sender): int
    {
        $total = 0;
        foreach (Branch::query()->where('is_active', true)->pluck('id') as $branchId) {
            $total += $sender->syncDeliveryReports((int) $branchId);
        }
        if ($total > 0) {
            $this->line("{$total} mesajın teslim durumu güncellendi.");
        }

        return self::SUCCESS;
    }
}
