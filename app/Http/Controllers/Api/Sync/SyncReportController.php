<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Api\ApiController;
use App\Sync\Models\SyncDevice;
use App\Sync\Server\TerminalStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Masaüstü kurulumunun küçük durum raporları — cihaz uçları (cihaz jetonu, 'sync.device').
 *
 *  POST sync/terminal-status     Biyometrik terminallerin bu kurulumdaki son durumu (satır olarak eşitlenmeyen sütunlar).
 *  POST sync/notification-reads  Masaüstünde okunan, sunucudan inmiş bildirimler.
 */
class SyncReportController extends ApiController
{
    public function terminalStatus(Request $request, TerminalStatusService $service): JsonResponse
    {
        $data = $request->validate([
            'devices' => ['present', 'array', 'max:'.TerminalStatusService::MAX_DEVICES],
            'devices.*.uuid' => ['required', 'uuid'],
            'devices.*.last_seen_at' => ['nullable', 'date'],
            'devices.*.last_pull_at' => ['nullable', 'date'],
            'devices.*.status' => ['nullable', 'in:ok,error'],
            'devices.*.error' => ['nullable', 'string', 'max:300'],
            'devices.*.record_count' => ['nullable', 'integer', 'min:0'],
            'devices.*.details' => ['nullable', 'array', 'max:40'],
        ]);
        /** @var SyncDevice $device */
        $device = $request->attributes->get('sync_device');

        return response()->json($service->report($device, $data['devices']) + ['server_time' => now()->toIso8601String()]);
    }

    public function notificationReads(Request $request, TerminalStatusService $service): JsonResponse
    {
        $data = $request->validate([
            'reads' => ['present', 'array', 'max:500'],
            'reads.*.uuid' => ['required', 'uuid'],
            'reads.*.read_at' => ['nullable', 'date'],
        ]);
        /** @var SyncDevice $device */
        $device = $request->attributes->get('sync_device');

        return response()->json(['marked' => $service->notificationReads($device, $data['reads'])]);
    }
}
