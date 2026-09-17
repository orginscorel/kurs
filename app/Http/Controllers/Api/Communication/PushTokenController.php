<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Api\ApiController;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Mobil/PWA push token kaydı (Expo). Her oturum açan kullanıcı kendi cihazını kaydedebilir. */
class PushTokenController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(['ios', 'android', 'web'])],
            'token' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        PushToken::query()->updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'], 'device_name' => $data['device_name'] ?? null, 'last_used_at' => now()],
        );

        return $this->ok('Cihaz kaydedildi.');
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        PushToken::query()->where('user_id', $request->user()->id)->where('token', $token)->delete();

        return $this->ok('Cihaz kaydı kaldırıldı.');
    }
}
