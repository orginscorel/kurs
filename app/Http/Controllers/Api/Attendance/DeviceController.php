<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Device;
use App\Services\Attendance\DeviceService;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Cihaz CRUD + jeton üretme/yenileme + çevrim içi durumu. */
class DeviceController extends ApiController
{
    public function __construct(private readonly DeviceService $devices) {}

    public function index(): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $devices = Device::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->orderBy('name')->get();

        $lastEvents = DB::table('attendance_events')->where('branch_id', $branchId)->whereNotNull('device_id')
            ->selectRaw('device_id, MAX(occurred_at) AS last_event_at, COUNT(*) AS event_count')
            ->groupBy('device_id')->get()->keyBy('device_id');

        return response()->json(['data' => $devices->map(function (Device $d) use ($lastEvents) {
            $le = $lastEvents[$d->id] ?? null;

            return [
                'id' => $d->id, 'name' => $d->name, 'kind' => $d->kind, 'location' => $d->location, 'direction' => $d->direction,
                'serial_no' => $d->serial_no, 'is_active' => $d->is_active, 'is_online' => $d->isOnline(),
                'last_seen_at' => $d->last_seen_at, 'firmware' => $d->firmware, 'api_token_prefix' => $d->api_token_prefix,
                'has_token' => $d->api_token_hash !== '', 'last_event_at' => $le->last_event_at ?? null, 'event_count' => (int) ($le->event_count ?? 0),
            ];
        })]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $device = $this->devices->create($data);

        return response()->json(['message' => 'Cihaz eklendi.', 'id' => $device->id], 201);
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $this->devices->update($device, $this->validated($request, $device));

        return $this->ok('Cihaz güncellendi.');
    }

    public function destroy(Device $device): JsonResponse
    {
        $this->devices->delete($device);

        return $this->ok('Cihaz silindi.');
    }

    /** Düz jeton yalnız bu yanıtta bir kez döner. */
    public function issueToken(Device $device): JsonResponse
    {
        $token = $this->devices->issueToken($device);

        return response()->json(['message' => 'Yeni erişim jetonu üretildi. Bu değeri şimdi kopyalayın; bir daha gösterilmeyecek.', 'token' => $token]);
    }

    private function validated(Request $request, ?Device $device = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(['fingerprint', 'rfid', 'qr', 'face', 'gateway'])],
            'location' => ['nullable', 'string', 'max:120'],
            'direction' => ['required', Rule::in(['entry', 'exit', 'both'])],
            'serial_no' => ['nullable', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], ['name' => 'Cihaz adı', 'kind' => 'Cihaz türü', 'location' => 'Konum', 'direction' => 'Geçiş yönü', 'serial_no' => 'Seri numarası', 'is_active' => 'Durum']);
    }
}
