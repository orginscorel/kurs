<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Donanım köprüsü (Local Device Gateway) kimlik doğrulaması.
 * Başlık: Authorization: Bearer dev_xxxxxxxx…  — veritabanında yalnızca SHA-256 özeti tutulur.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $device = $token
            ? Device::query()->where('api_token_hash', hash('sha256', $token))->where('is_active', true)->first()
            : null;

        if (! $device) {
            return response()->json([
                'message' => 'Cihaz kimliği doğrulanamadı.',
                'error_code' => 'device_unauthorized',
            ], 401);
        }

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
