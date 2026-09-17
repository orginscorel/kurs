<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Sync\Models\SyncDevice;
use App\Sync\Server\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Oturumsuz: sağlık yoklaması ve cihaz eşleştirme. */
class SyncPairController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'app' => 'kurs',
            'node' => config('kurs.node'),
            'protocol' => 2,   // 2: dosya eşitlemesi, finans komutları, çevrimiçi parola, anahtar parmak izi
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function pair(Request $request, DeviceService $devices): JsonResponse
    {
        if (config('kurs.node') !== 'server') {
            return response()->json(['message' => 'Eşleştirme yalnız web sunucusunda yapılır.', 'error_code' => 'not_server'], 409);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'login' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'max:120'],
            'platform' => ['required', Rule::in(array_keys(SyncDevice::PLATFORMS))],
            'mode' => ['nullable', Rule::in(['desktop', 'mobile'])],
            'app_version' => ['nullable', 'string', 'max:40'],
            'public_key' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($devices->pair($data, $request), 201);
    }
}
