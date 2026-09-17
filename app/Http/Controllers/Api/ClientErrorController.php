<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tarayıcıda oluşan arayüz hatalarını sunucu loguna yazar (kullanıcıya teknik ayrıntı gösterilmez).
 */
class ClientErrorController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'context' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:3000'],
            'component_stack' => ['nullable', 'string', 'max:2000'],
            'url' => ['nullable', 'string', 'max:500'],
        ]);

        Log::warning('İstemci arayüz hatası', $data + [
            'user_id' => $request->user()?->id,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
        ]);

        return response()->json(['ok' => true]);
    }
}
