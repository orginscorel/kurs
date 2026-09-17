<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMessageCampaign;
use App\Models\MessageCampaign;
use App\Services\Campaigns\CampaignService;
use Illuminate\Console\Command;

/** Toplu gönderim zamanlayıcısı: zamanı gelenleri başlatır, süren gönderimler için kuyruk işi atar. */
class RunMessageCampaigns extends Command
{
    protected $signature = 'kurs:campaigns-run';

    protected $description = 'Zamanı gelen toplu e-posta/SMS gönderimlerini başlatır ve kuyruğa parça parça gönderim işi atar';

    public function handle(): int
    {
        $started = CampaignService::startDue();
        if ($started > 0) {
            $this->line("{$started} zamanlanmış gönderim başlatıldı.");
        }

        MessageCampaign::query()->withoutGlobalScope('branch')->where('status', 'sending')
            ->get(['id', 'branch_id'])
            ->each(fn ($c) => ProcessMessageCampaign::dispatch($c->id, $c->branch_id));

        return self::SUCCESS;
    }
}
