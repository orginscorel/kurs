<?php

namespace App\Jobs;

use App\Services\Push\ExpoPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Uygulama bildirimini eşlik eden anlık push olarak da gönderir (kayıtlı cihaz yoksa sessizce atlanır). */
class SendExpoPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly int $userId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
    ) {}

    public function handle(ExpoPushService $push): void
    {
        $push->sendToUser($this->userId, $this->body, $this->title, $this->url);
    }

    public function failed(?\Throwable $e): void
    {
        // Push en iyi çaba (best-effort) kanaldır; başarısızlık uygulama bildirimini etkilemez.
    }
}
