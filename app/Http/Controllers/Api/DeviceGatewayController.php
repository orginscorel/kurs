<?php

namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Services\Attendance\DuplicateEventException;
use App\Services\Attendance\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Yerel Donanım Köprüsü (Local Device Gateway) uç noktaları.
 *
 * Köprü kurumdaki bilgisayarda çalışır; cihazdan okuduğu olayları yerel SQLite kuyruğuna
 * yazar ve bu uca toplu gönderir. İnternet kesilirse kuyrukta bekler; geri gelince
 * aynı idempotency_key ile yeniden gönderir → sunucu çift kayıt oluşturmaz.
 */
class DeviceGatewayController extends ApiController
{
    public function events(Request $request, PresenceService $presence): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.idempotency_key' => ['required', 'string', 'max:80'],
            'events.*.identifier' => ['nullable', 'string', 'max:120'],
            'events.*.identifier_kind' => ['nullable', 'in:fingerprint,card,qr'],
            'events.*.student_id' => ['nullable', 'integer'],
            'events.*.event_type' => ['required', 'in:ENTRY,EXIT,AUTO'],
            'events.*.occurred_at' => ['required', 'date', 'before_or_equal:+10 minutes'],
        ]);

        /** @var Device $device */
        $device = $request->attributes->get('device');

        // Eski olaylar önce: çevrimdışı kuyruk sırası bozuk gelse de durum doğru kurulur.
        $events = collect($data['events'])->sortBy('occurred_at')->values();
        $results = [];

        foreach ($events as $event) {
            try {
                $results[] = ['idempotency_key' => $event['idempotency_key']] + $presence->ingest($event, $device);
            } catch (DuplicateEventException) {
                $results[] = ['idempotency_key' => $event['idempotency_key'], 'status' => 'duplicate'];
            }
        }

        return response()->json([
            'results' => $results,
            'summary' => collect($results)->countBy('status'),
            'server_time' => now()->toAtomString(),
        ]);
    }

    /** Köprünün cihaza yükleyeceği aktif kimlik eşlemeleri (ham biyometrik veri İÇERMEZ). */
    public function identities(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $identities = DeviceIdentity::query()
            ->withoutGlobalScope('branch')
            ->where('device_identities.branch_id', $device->branch_id)
            ->where('device_identities.is_active', true)
            ->where('device_identities.person_type', 'student')
            ->join('students', 'students.id', '=', 'device_identities.person_id')
            ->whereNull('students.deleted_at')
            ->whereIn('students.status', ['active', 'enrolled'])
            ->get(['device_identities.kind', 'device_identities.identifier', 'students.id as student_id', 'students.full_name', 'students.student_no']);

        return response()->json(['data' => $identities, 'server_time' => now()->toAtomString()]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $data = $request->validate(['firmware' => ['nullable', 'string', 'max:60'], 'queue_size' => ['nullable', 'integer']]);

        if (! empty($data['firmware'])) {
            $device->forceFill(['firmware' => $data['firmware']])->saveQuietly();
        }

        return response()->json(['ok' => true, 'device' => $device->name, 'server_time' => now()->toAtomString()]);
    }
}
