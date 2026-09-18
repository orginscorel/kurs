<?php

namespace App\Http\Controllers\Api\Sync;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Auth\SessionDirectory;
use App\Sync\Models\SyncDevice;
use App\Sync\Server\DeviceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Masaüstü uygulaması oturumları — cihaz uçları (cihaz jetonu, 'sync.device').
 *
 *  POST sync/sessions               Cihazın açık yerel oturum raporu (+ uygulanan kapatmaların onayı).
 *                                   Yanıt: bekleyen kapatma istekleri.
 *  GET  sync/user-sessions          Uygulamada oturum açmış kullanıcının TÜM oturumları (web + uygulama + mobil).
 *  POST sync/user-sessions/revoke   Aynı kullanıcının bir oturumunu (ya da mevcut dışındakilerin hepsini) kapatır.
 *
 * Vekilli uçlarda yetki kanıtı: cihaz o kullanıcı için o oturum özetini (session) RAPORLAMIŞ olmalı;
 * yani yalnız cihazda gerçekten oturum açmış kullanıcı kendi oturumlarını görür/kapatır.
 */
class SyncSessionController extends ApiController
{
    private function device(Request $request): SyncDevice
    {
        return $request->attributes->get('sync_device');
    }

    public function report(Request $request, DeviceSessionService $service): JsonResponse
    {
        $data = $request->validate([
            'sessions' => ['present', 'array', 'max:'.DeviceSessionService::MAX_SESSIONS],
            'sessions.*.user' => ['required', 'uuid'],
            'sessions.*.hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'sessions.*.last_activity' => ['nullable', 'integer', 'min:0'],
            'sessions.*.user_agent' => ['nullable', 'string', 'max:255'],
            'acks' => ['nullable', 'array', 'max:500'],
            'acks.*' => ['integer', 'min:1'],
        ]);

        $device = $this->device($request);

        return response()->json($service->report($device, $data['sessions'], $data['acks'] ?? []) + [
            'device' => ['name' => $device->name, 'code' => $device->code],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function userSessions(Request $request, SessionDirectory $directory): JsonResponse
    {
        [$user, $hash] = $this->proxiedUser($request);
        $logins = LoginEvent::query()->where('user_id', $user->id)->latest('created_at')->limit(15)->get();

        return response()->json([
            'data' => $directory->forUser($user, ['app' => $hash]),
            'recent_logins' => $logins,
        ]);
    }

    public function revoke(Request $request, SessionDirectory $directory): JsonResponse
    {
        $request->validate([
            'id' => ['required_without:all', 'nullable', 'string', 'max:80'],
            'all' => ['nullable', 'boolean'],
        ]);
        [$user, $hash] = $this->proxiedUser($request);
        $device = $this->device($request);

        if ($request->boolean('all')) {
            $res = $directory->revokeOthers($user, $user, ['app' => $hash], $device);

            return response()->json($res);
        }

        return $this->ok($directory->revoke($user, (string) $request->input('id'), $user, ['app' => $hash], $device));
    }

    /** @return array{0: User, 1: string} */
    private function proxiedUser(Request $request): array
    {
        $data = $request->validate([
            'user' => ['required', 'uuid'],
            'session' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);
        $device = $this->device($request);
        /** @var User|null $user */
        $user = User::query()->where('uuid', $data['user'])->first();
        if (! $user || ! $user->is_active || ! app(DeviceSessionService::class)->deviceHasSession($device, (int) $user->id, $data['session'])) {
            throw new BusinessRuleException('Bu kullanıcının oturum bilgisi bu cihazdan görüntülenemez. Uygulamada yeniden oturum açın.', 'forbidden', [], 403);
        }

        return [$user, strtolower($data['session'])];
    }
}
