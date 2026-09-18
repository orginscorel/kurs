<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Sync\Local\LocalState;
use App\Sync\Local\RejectedRetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** "N reddedilen" listesi: tablo, kayıt etiketi, neden, tarih, deneme sayısı. */
    public function rejected(RejectedRetry $retry): JsonResponse
    {
        if (config('kurs.node') !== 'local') {
            return response()->json(['node' => 'server', 'data' => []]);
        }

        return response()->json(['node' => 'local', 'data' => $retry->list()]);
    }

    /** Satır başına ya da toplu "Yeniden dene" (bir sonraki eşitleme turunda, hemen istenir). */
    public function retryRejected(Request $request, RejectedRetry $retry): JsonResponse
    {
        if (config('kurs.node') !== 'local') {
            return response()->json(['node' => 'server']);
        }
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer', 'min:1']]);
        $retry->requestManual(isset($data['ids']) ? array_map('intval', $data['ids']) : null);
        Audit::log('sync.rejected_retry', isset($data['ids']) ? sprintf('%d reddedilen değişikliğin yeniden denenmesini istedi.', count($data['ids'])) : 'tüm reddedilen değişikliklerin yeniden denenmesini istedi.');

        return response()->json(['message' => 'Yeniden deneme istendi; birkaç saniye içinde sonuçlanır.', 'node' => 'local']);
    }
}
