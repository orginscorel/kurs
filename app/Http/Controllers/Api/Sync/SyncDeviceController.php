<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Api\ApiController;
use App\Sync\Models\SyncConflict;
use App\Sync\Models\SyncDevice;
use App\Sync\Server\DeviceService;
use App\Sync\Server\PullService;
use App\Sync\Server\PushService;
use App\Sync\Server\SnapshotService;
use App\Sync\Server\SyncReject;
use App\Sync\SyncNumbers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cihaz (masaüstü yerel kurulum / mobil) eşitleme uçları. Protokol: docs/SYNC.md
 */
class SyncDeviceController extends ApiController
{
    private function device(Request $request): SyncDevice
    {
        return $request->attributes->get('sync_device');
    }

    public function status(Request $request, DeviceService $devices): JsonResponse
    {
        $device = $this->device($request);
        if ($request->filled('pending')) {
            $device->forceFill(['pending_reported' => max(0, min(1000000, $request->integer('pending')))])->save();
        }

        return response()->json([
            'node' => 'server',
            'server_time' => now()->toIso8601String(),
            'cursor' => (int) (DB::table('sync_changes')->max('id') ?? 0),
            'device' => $devices->present($device),
            'open_conflicts' => SyncConflict::query()->where('branch_id', $device->branch_id)->where('status', 'open')->count(),
            // Kurum veri anahtarının parmak izi (anahtar değil): cihaz farklıysa key-bundle ile yeniler
            'data_key' => \App\Support\Sensitive::dataKeyFingerprint(),
            'protocol' => 2,
        ]);
    }

    /** Yerel kurulumdan çevrimiçi parola değişikliği (mevcut parola sunucuda doğrulanır). */
    public function password(Request $request, DeviceService $devices): JsonResponse
    {
        $data = $request->validate([
            'user' => ['required', 'uuid'],
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'max:200', \Illuminate\Validation\Rules\Password::min(10)->letters()->numbers()],
        ], [
            'current_password.required' => 'Mevcut parolanızı girin.',
            'password.min' => 'Yeni parola en az :min karakter olmalı.',
            'password.letters' => 'Yeni parola en az bir harf içermeli.',
            'password.numbers' => 'Yeni parola en az bir rakam içermeli.',
        ]);
        $device = $this->device($request);
        $res = app(\App\Support\BranchContext::class)->run((int) $device->branch_id,
            fn () => $devices->changePassword($device, $data['user'], $data['current_password'], $data['password']));

        return response()->json(['message' => 'Parola güncellendi.'] + $res);
    }

    public function pull(Request $request, PullService $pull): JsonResponse
    {
        $data = $request->validate(['cursor' => ['required', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:2000']]);

        return response()->json($pull->pull($this->device($request), $request->user(), (int) $data['cursor'], (int) ($data['limit'] ?? 500)));
    }

    public function push(Request $request, PushService $push): JsonResponse
    {
        $max = (int) config('sync.push_limit', 1000);
        $data = $request->validate([
            'base_cursor' => ['nullable', 'integer', 'min:0'],
            'device_time' => ['nullable', 'date'],
            'changes' => ['present', 'array', 'max:'.$max],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.table' => ['nullable', 'string', 'max:64'],
            'changes.*.command' => ['nullable', 'string', 'max:64'],
            'changes.*.op' => ['nullable', 'string', 'max:10'],
            'changes.*.row' => ['nullable', 'string', 'max:36'],
            'changes.*.fields' => ['nullable', 'array'],
            'changes.*.args' => ['nullable', 'array'],
            'changes.*.uuids' => ['nullable', 'array'],
            'changes.*.numbers' => ['nullable', 'array'],
            'changes.*.at' => ['nullable', 'date'],
        ], ['changes.max' => "Bir pakette en fazla $max değişiklik gönderilebilir."]);

        $changes = array_map(fn ($c) => $c, $request->input('changes', []));

        return response()->json($push->push($this->device($request), $changes, $data['base_cursor'] ?? null, $data['device_time'] ?? null));
    }

    public function manifest(Request $request, SnapshotService $snapshots): JsonResponse
    {
        return response()->json($snapshots->manifest($this->device($request), $request->user()));
    }

    public function snapshot(Request $request, SnapshotService $snapshots, string $table): JsonResponse
    {
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:5000']]);
        try {
            return response()->json($snapshots->page($this->device($request), $request->user(), $table, (int) ($data['after'] ?? 0), (int) ($data['limit'] ?? 1000)));
        } catch (SyncReject $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->errorCode === 'forbidden' ? 403 : 404);
        }
    }

    public function rows(Request $request, SnapshotService $snapshots): JsonResponse
    {
        $data = $request->validate(['table' => ['required', 'string', 'max:64'], 'uuids' => ['required', 'array', 'max:500'], 'uuids.*' => ['uuid']]);
        try {
            return response()->json(['table' => $data['table'], 'rows' => $snapshots->rows($this->device($request), $request->user(), $data['table'], $data['uuids'])]);
        } catch (SyncReject $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->errorCode === 'forbidden' ? 403 : 404);
        }
    }

    public function numberBlock(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'in:'.implode(',', SyncNumbers::LEASABLE)], 'size' => ['nullable', 'integer', 'min:1', 'max:500']]);
        $device = $this->device($request);
        $block = SyncNumbers::lease((int) $device->branch_id, (int) $device->id, $data['name'], (int) ($data['size'] ?? config('sync.number_block_size', 50)));

        return response()->json(['block' => $block]);
    }

    public function keyBundle(Request $request, DeviceService $devices): JsonResponse
    {
        return response()->json(['key_bundle' => $devices->keyBundle($this->device($request))]);
    }
}
