<?php

namespace App\Sync\Http;

use App\Sync\Models\SyncDevice;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Eşitleme uçları yalnız eşleştirilmiş, iptal edilmemiş cihaz jetonuyla çağrılır.
 * Cihaz isteğin özniteliğine konur: $request->attributes->get('sync_device').
 */
class EnsureSyncDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! in_array('sync', (array) $token->abilities, true)) {
            return response()->json(['message' => 'Bu uç yalnız eşleştirilmiş cihazlara açıktır.', 'error_code' => 'device_token_required'], 403);
        }
        $device = SyncDevice::query()->where('token_id', $token->id)->first();
        if (! $device || ! $device->isActive()) {
            return response()->json(['message' => 'Bu cihazın eşitleme erişimi iptal edilmiş. Yeniden eşleştirin.', 'error_code' => 'device_revoked'], 401);
        }
        $request->attributes->set('sync_device', $device);

        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip(),
                'app_version' => mb_substr((string) ($request->header('X-App-Version') ?: $device->app_version), 0, 40) ?: null])->save();
        }

        return $next($request);
    }
}
