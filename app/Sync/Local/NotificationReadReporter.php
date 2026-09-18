<?php

namespace App\Sync\Local;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Yerel kurulum → sunucu: masaüstünde OKUNDU işaretlenen bildirimler.
 *
 * Bildirimler (app_notifications) sunucudan satır olarak iner (sunucu-otoriteli). Masaüstünde "okundu" yapılan
 * satırın yalnız uuid'si + okunma anı küçük bir kuyrukta (sync_state) bekler ve her turda gönderilir; çevrimdışıyken
 * kuyruk kaybolmaz. Yerelde üretilmiş (uuid'siz) bildirimler kuyruğa girmez — sunucuda karşılığı yoktur.
 */
class NotificationReadReporter
{
    public const QUEUE_KEY = 'notification_reads_pending';

    private const MAX_QUEUE = 2000;

    private const BATCH = 500;

    public function __construct(
        private readonly SyncClient $client,
        private readonly LocalState $state,
    ) {}

    /**
     * NotificationController::markRead (yerel düğüm) çağırır.
     *
     * @param list<string> $uuids
     */
    public function enqueue(array $uuids, ?string $readAt = null): void
    {
        $uuids = array_values(array_filter($uuids, fn ($u) => is_string($u) && $u !== ''));
        if ($uuids === []) {
            return;
        }
        $queue = $this->queue();
        $at = $readAt ?? now()->toIso8601String();
        foreach ($uuids as $u) {
            $queue[$u] ??= $at;
        }
        if (count($queue) > self::MAX_QUEUE) {
            $queue = array_slice($queue, -self::MAX_QUEUE, null, true);
        }
        $this->state->put(self::QUEUE_KEY, json_encode($queue));
    }

    /** @return array<string, string> uuid => okunma anı */
    public function queue(): array
    {
        return (array) json_decode((string) $this->state->get(self::QUEUE_KEY, '{}'), true);
    }

    /** Eşitleme turundan çağrılır: hata turu düşürmez, kuyruk korunur. */
    public function runSafely(): array
    {
        try {
            return $this->report();
        } catch (\Throwable $e) {
            Log::info('Bildirim okundu bilgisi gönderilemedi', ['e' => $e->getMessage()]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** @throws SyncHttpException */
    public function report(): array
    {
        $queue = $this->queue();
        if ($queue === [] || ! $this->client->isPaired()) {
            return ['status' => $queue === [] ? 'nothing' : 'unpaired', 'sent' => 0];
        }
        $sent = 0;
        foreach (array_chunk($queue, self::BATCH, true) as $part) {
            $reads = [];
            foreach ($part as $uuid => $at) {
                $reads[] = ['uuid' => (string) $uuid, 'read_at' => $at];
            }
            $this->client->notificationReads($reads);
            // Gönderilenleri kuyruktan düş (arada yeni gelenler kalsın)
            $now = $this->queue();
            foreach (array_keys($part) as $uuid) {
                unset($now[$uuid]);
            }
            $this->state->put(self::QUEUE_KEY, $now === [] ? null : json_encode($now));
            $sent += count($part);
        }

        return ['status' => 'ok', 'sent' => $sent];
    }

    /** Okundu yapılacak satırların sunucudan inmiş (uuid'li) olanları. */
    public static function uuidsOf(int $userId, ?array $ids, bool $all): array
    {
        return DB::table('app_notifications')->where('user_id', $userId)->whereNull('read_at')->whereNotNull('uuid')
            ->when(! $all, fn ($q) => $q->whereIn('id', $ids ?? []))
            ->pluck('uuid')->map(fn ($u) => (string) $u)->all();
    }
}
