<?php

namespace App\Jobs;

use App\Models\MessageCampaign;
use App\Services\Campaigns\CampaignSender;
use App\Support\BranchContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bir toplu gönderimin sıradaki parçasını gönderir. Zamanlayıcı (kurs:campaigns-run) her dakika
 * süren gönderimler için bu işi kuyruğa atar; ShouldBeUnique aynı gönderimin iki işinin çakışmasını engeller.
 */
class ProcessMessageCampaign implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $campaignId, public readonly int $branchId) {}

    public function uniqueId(): string
    {
        return 'campaign:'.$this->campaignId;
    }

    public function handle(CampaignSender $sender): void
    {
        app(BranchContext::class)->run($this->branchId, function () use ($sender) {
            $campaign = MessageCampaign::query()->find($this->campaignId);
            if ($campaign && $campaign->status === 'sending') {
                $sender->process($campaign);
            }
        });
    }
}
