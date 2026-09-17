<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Services\Attendance\ZkDeviceService;
use App\Services\Attendance\ZkPullService;
use App\Services\Devices\Zk\Exceptions\ZkException;
use App\Services\Devices\Zk\ZkConnectionSettings;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Biyometrik terminal köprüsü uçları (ZKTeco / Perkotek YT-33).
 *
 * ÖNEMLİ: Bu uçlar cihaza LAN üzerinden bağlanmaya çalışır. Web sunucusu kurumun yerel ağına
 * ULAŞAMAZ; bu yüzden bağlantı gerektiren uçlar (test, kullanıcı listesi, elle çekme) pratikte
 * masaüstü yerel düğümde (KURS_NODE=local) çağrılır. Sunucuda çağrılırsa temiz bir
 * "cihaza ulaşılamadı" yanıtı döner, istek asılı kalmaz (zaman aşımı zorunludur).
 *
 * Yetki: hepsi `devices.manage`.
 */
class ZkDeviceController extends ApiController
{
    public function __construct(
        private readonly ZkDeviceService $devices,
        private readonly ZkPullService $pull,
    ) {}

    /** Cihazın LAN bağlantı bilgilerini kaydeder (iletişim şifresi şifreli saklanır). */
    public function saveConnection(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'transport' => ['nullable', Rule::in(['tcp', 'udp'])],
            'comm_key' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
        ], [
            'ip.required' => 'Cihazın IP adresi zorunludur.',
            'ip.ip' => 'Geçerli bir IP adresi girin (ör. 192.168.1.50).',
            'port.between' => 'Port 1 ile 65535 arasında olmalıdır (fabrika değeri 4370).',
            'transport.in' => 'Bağlantı türü tcp ya da udp olmalıdır.',
            'comm_key.regex' => 'İletişim şifresi yalnız rakamlardan oluşur (cihaz menüsündeki değer).',
            'comm_key.max' => 'İletişim şifresi en çok 20 hane olabilir.',
        ], ['ip' => 'IP adresi', 'port' => 'Port', 'transport' => 'Bağlantı türü', 'comm_key' => 'İletişim şifresi']);

        $this->devices->saveConnection($device, $data);

        return $this->ok('Cihaz bağlantı bilgileri kaydedildi.', ['durum' => $this->connectionState($device->fresh())]);
    }

    /**
     * Bağlantı testi. Cihaza ulaşılamazsa da 200 döner; yanıttaki `durum` alanı 'hata' olur ve
     * `oneri` kullanıcının cihaz menüsünde ne yapması gerektiğini anlatır.
     */
    public function test(Request $request, ?Device $device = null): JsonResponse
    {
        if ($device) {
            $settings = $this->devices->settingsFor($device);
        } else {
            $data = $request->validate([
                'ip' => ['required', 'ip'],
                'port' => ['nullable', 'integer', 'between:1,65535'],
                'transport' => ['nullable', Rule::in(['tcp', 'udp'])],
                'comm_key' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
            ], [
                'ip.required' => 'Cihazın IP adresi zorunludur.',
                'ip.ip' => 'Geçerli bir IP adresi girin (ör. 192.168.1.50).',
                'comm_key.regex' => 'İletişim şifresi yalnız rakamlardan oluşur.',
            ], ['ip' => 'IP adresi', 'port' => 'Port', 'transport' => 'Bağlantı türü', 'comm_key' => 'İletişim şifresi']);

            $settings = ZkConnectionSettings::fromArray([
                'host' => $data['ip'],
                'port' => $data['port'] ?? null,
                'transport' => $data['transport'] ?? null,
                'comm_key' => $data['comm_key'] ?? null,
            ]);
        }

        return response()->json($this->devices->test($settings));
    }

    /** Cihazdaki kullanıcılar + hâlihazırdaki eşleme + öğrenci önerisi. */
    public function users(Device $device): JsonResponse
    {
        try {
            return response()->json(['data' => $this->devices->usersWithSuggestions($device)]);
        } catch (ZkException $e) {
            return response()->json(['data' => [], 'hata' => $e->toArray()], 200);
        }
    }

    /** Kaydedilmiş eşlemeler (cihaz kullanıcı no ↔ öğrenci). */
    public function mappings(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();

        $query = DeviceIdentity::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->where('kind', 'fingerprint')->where('person_type', 'student');

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where('identifier', 'like', $search.'%');
        }

        $identities = $query->orderBy('identifier')->paginate($this->perPage($request));
        $students = \App\Models\Student::query()->withoutGlobalScope('branch')
            ->whereIn('id', collect($identities->items())->pluck('person_id'))->get(['id', 'full_name', 'student_no'])->keyBy('id');

        return $this->paginated($identities, function (DeviceIdentity $identity) use ($students) {
            $student = $students->get($identity->person_id);

            return [
                'id' => $identity->id,
                'kullanici_no' => $identity->identifier,
                'ogrenci_id' => $identity->person_id,
                'ogrenci' => $student?->full_name,
                'ogrenci_no' => $student?->student_no,
                'aktif' => (bool) $identity->is_active,
            ];
        });
    }

    /** Eşleme ekler/günceller. */
    public function storeMapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kullanici_no' => ['required', 'string', 'max:120'],
            'ogrenci_id' => ['required', 'integer'],
        ], [
            'kullanici_no.required' => 'Cihazdaki kullanıcı numarası zorunludur.',
            'ogrenci_id.required' => 'Öğrenci seçilmelidir.',
        ], ['kullanici_no' => 'Cihaz kullanıcı numarası', 'ogrenci_id' => 'Öğrenci']);

        $identity = $this->devices->link(app(BranchContext::class)->require(), $data['kullanici_no'], (int) $data['ogrenci_id']);

        return $this->ok('Eşleme kaydedildi.', ['id' => $identity->id]);
    }

    public function destroyMapping(DeviceIdentity $identity): JsonResponse
    {
        $this->devices->unlink($identity);

        return $this->ok('Eşleme kaldırıldı.');
    }

    /**
     * Cihazdan gelmiş ama hiçbir öğrenciye bağlanamamış okutmalar.
     * (Eşleştirme için var olan uç kullanılır: POST attendance/live/unmatched/{event}/match)
     */
    public function pending(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();

        $events = AttendanceEvent::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->where('is_matched', false)
            ->orderByDesc('occurred_at')
            ->paginate($this->perPage($request));

        $devices = Device::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->pluck('name', 'id');

        return $this->paginated($events, fn (AttendanceEvent $event) => [
            'id' => $event->id,
            'kullanici_no' => $event->raw_identifier,
            'zaman' => $event->occurred_at,
            'yon' => $event->event_type,
            'kaynak' => $event->source,
            'cihaz' => $devices[$event->device_id] ?? null,
        ]);
    }

    /** Elle çekme tetikler (kilitlidir: aynı cihaz için iki çekme aynı anda çalışmaz). */
    public function pullNow(Request $request, Device $device): JsonResponse
    {
        try {
            $result = $this->pull->pull($device, $request->boolean('tam'));
        } catch (ZkException $e) {
            return response()->json(['durum' => 'hata'] + $e->toArray(), 422);
        }

        return response()->json(['durum' => 'ok'] + $result);
    }

    /** Cihazın köprü durumu (son çekme, hata, imleç). */
    public function status(Device $device): JsonResponse
    {
        return response()->json($this->connectionState($device));
    }

    private function connectionState(Device $device): array
    {
        return [
            'protokol' => $device->protocol,
            'ip' => $device->zk_ip,
            'port' => $device->zk_port,
            'aktarim' => $device->zk_transport,
            'sifre_tanimli' => $device->zk_comm_key !== null,
            'son_cekme' => $device->zk_last_pull_at,
            'son_durum' => $device->zk_last_status,
            'son_hata' => $device->zk_last_error,
            'son_kayit_sayisi' => (int) $device->zk_last_record_count,
            'imlec' => $device->zk_cursor_at,
        ];
    }
}
