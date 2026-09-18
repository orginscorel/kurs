<?php

namespace App\Http\Controllers\Api;

use App\Models\AppNotification;
use App\Sync\ChangeRecorder;
use App\Sync\Local\NotificationReadReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $items = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->when($request->query('filter') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('id')
            ->limit(min(100, (int) $request->integer('limit', 40)))
            ->get(['id', 'type', 'title', 'body', 'action_url', 'read_at', 'created_at']);

        return response()->json(['data' => $items]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => AppNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['array'], 'ids.*' => ['integer'], 'all' => ['boolean']]);
        $userId = (int) $request->user()->id;

        // Masaüstü (yerel kurulum): sunucudan inmiş bildirimin okundu bilgisi sunucuya da gitsin (kuyruk; çevrimdışıyken bekler)
        if (ChangeRecorder::isLocalNode()) {
            app(NotificationReadReporter::class)->enqueue(NotificationReadReporter::uuidsOf($userId, $data['ids'] ?? [], ! empty($data['all'])));
        }

        // updated_at: eşitleme süpürücüsü okundu değişikliğini hızlı kipte de görsün (masaüstüne iner)
        $update = ['read_at' => now()];
        if (Schema::hasColumn('app_notifications', 'updated_at')) {
            $update['updated_at'] = now();
        }
        AppNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->when(empty($data['all']), fn ($q) => $q->whereIn('id', $data['ids'] ?? []))
            ->update($update);

        return $this->ok('Bildirimler okundu olarak işaretlendi.');
    }
}
