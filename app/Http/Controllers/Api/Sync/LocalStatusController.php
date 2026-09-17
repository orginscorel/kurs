<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Sync\Local\LocalState;
use Illuminate\Http\JsonResponse;

/** Yerel düğüm üst çubuk göstergesi: Çevrimdışı / Eşitleniyor / Eşitlendi · N bekleyen. */
class LocalStatusController extends Controller
{
    public function show(LocalState $state): JsonResponse
    {
        if (config('kurs.node') !== 'local') {
            return response()->json(['node' => 'server']);
        }

        return response()->json(['node' => 'local'] + $state->summary());
    }

    public function syncNow(LocalState $state): JsonResponse
    {
        if (config('kurs.node') !== 'local') {
            return response()->json(['node' => 'server']);
        }
        $state->requestSync();

        return response()->json(['message' => 'Eşitleme istendi.', 'node' => 'local'] + $state->summary());
    }
}
