<?php

namespace App\Services\Notifications;

use App\Jobs\SendExpoPush;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Uygulama içi bildirim merkezi (`app_notifications`, üst çubuktaki NotificationCenter
 * bu tabloyu zaten `/notifications` uçlarıyla okuyor) + eşlik eden Expo push denemesi.
 */
class NotificationService
{
    /**
     * @param  User|iterable<User>|iterable<int>|int  $users
     * @param  array<string,mixed>|null  $data  ek bağlam; `once` anahtarı verilirse aynı kullanıcıya aynı anahtarla ikinci bildirim yazılmaz
     * @return int yazılan bildirim sayısı
     */
    public function notify(User|iterable|int $users, string $type, string $title, string $body, ?string $url = null, ?array $data = null): int
    {
        $userIds = $this->normalizeIds(is_int($users) ? [$users] : $users);

        if (! empty($data['once']) && $userIds !== []) {
            $already = AppNotification::query()->whereIn('user_id', $userIds)->where('data->once', (string) $data['once'])->pluck('user_id')->all();
            $userIds = array_values(array_diff($userIds, $already));
        }

        if (empty($userIds)) {
            return 0;
        }

        $type = mb_substr($type, 0, 20);
        $rows = array_map(fn ($id) => [
            'user_id' => $id, 'type' => $type, 'title' => mb_substr($title, 0, 160), 'body' => mb_substr($body, 0, 1000),
            'action_url' => $url, 'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, 'read_at' => null, 'created_at' => now(),
        ], $userIds);

        AppNotification::query()->insert($rows);

        DB::afterCommit(function () use ($userIds, $title, $body, $url) {
            foreach ($userIds as $id) {
                SendExpoPush::dispatch($id, $title, $body, $url);
            }
        });

        return count($userIds);
    }

    /** @return list<int> */
    private function normalizeIds(User|iterable $users): array
    {
        if ($users instanceof User) {
            return [$users->id];
        }

        $ids = [];
        foreach ($users as $u) {
            $ids[] = $u instanceof User ? $u->id : (int) $u;
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
