<?php

namespace App\Http\Controllers\Api;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->when(empty($data['all']), fn ($q) => $q->whereIn('id', $data['ids'] ?? []))
            ->update(['read_at' => now()]);

        return $this->ok('Bildirimler okundu olarak işaretlendi.');
    }
}
