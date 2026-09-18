<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Device;
use App\Services\Attendance\DeviceDiagnostics;
use App\Services\Attendance\DeviceService;
use App\Services\Attendance\ZkDeviceService;
use App\Services\Devices\Adms\AdmsService;
use App\Services\Devices\Discovery\DeviceDiscoveryService;
use App\Services\Devices\Drivers\DriverRegistry;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AĞDA CİHAZ BUL — elle JSON/IP yazmayı bitiren uçlar.
 *
 * Tarama YEREL AĞI görebilen makinede anlamlıdır. Web sunucusunda çağrılırsa istek yine
 * temiz döner: `ortam.uyari` alanı kullanıcıya bunun neden böyle olduğunu ve ne yapması
 * gerektiğini TÜRKÇE söyler. Hiçbir çağrı asılı kalmaz (tarama süresi üst sınırlıdır).
 *
 * Yetki: hepsi `devices.manage`.
 */
class DeviceDiscoveryController extends ApiController
{
    public function __construct(
        private readonly DeviceDiscoveryService $discovery,
        private readonly DeviceService $devices,
        private readonly ZkDeviceService $zk,
        private readonly DeviceDiagnostics $diagnostics,
        private readonly DriverRegistry $drivers,
        private readonly AdmsService $adms,
    ) {}

    /** Tarama ekranı açılışı: bu makine hangi ağda, tarama mümkün mü? */
    public function environment(): JsonResponse
    {
        return response()->json($this->discovery->environment());
    }

    /** Desteklenen marka/protokoller + her birinin form alanları (ön yüzde liste elle yazılmaz). */
    public function protocols(): JsonResponse
    {
        return response()->json(['data' => $this->drivers->catalog()]);
    }

    /** Ağı tara. */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'aglar' => ['nullable', 'array', 'max:4'],
            'aglar.*' => ['string', 'max:20'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'zaman_asimi_ms' => ['nullable', 'integer', 'between:50,3000'],
            'sure_sn' => ['nullable', 'numeric', 'between:3,60'],
            'yayin' => ['nullable', 'boolean'],
            'kunye' => ['nullable', 'boolean'],
            'comm_key' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
        ], [
            'aglar.max' => 'Aynı anda en çok 4 ağ taranabilir.',
            'port.between' => 'Port 1 ile 65535 arasında olmalıdır (fabrika değeri 4370).',
            'zaman_asimi_ms.between' => 'Adres başına bekleme 50 ile 3000 milisaniye arasında olmalıdır.',
            'sure_sn.between' => 'Tarama süresi 3 ile 60 saniye arasında olmalıdır.',
            'comm_key.regex' => 'İletişim şifresi yalnız rakamlardan oluşur.',
        ], ['aglar' => 'Ağlar', 'port' => 'Port', 'zaman_asimi_ms' => 'Adres başına bekleme', 'sure_sn' => 'Tarama süresi']);

        $branchId = app(BranchContext::class)->require();
        $result = $this->discovery->scan($branchId, $data);

        Audit::log('device.network_scanned', 'Yerel ağda biyometrik terminal taraması yaptı ('.count($result['bulunanlar']).' aday).');

        return response()->json($result);
    }

    /** Tek adresi dene (kullanıcı IP'yi elle yazdıysa). */
    public function probe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'comm_key' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
        ], [
            'ip.required' => 'Cihazın IP adresi zorunludur.',
            'ip.ip' => 'Geçerli bir IP adresi girin (ör. 192.168.1.50).',
            'comm_key.regex' => 'İletişim şifresi yalnız rakamlardan oluşur.',
        ], ['ip' => 'IP adresi', 'port' => 'Port', 'comm_key' => 'İletişim şifresi']);

        $found = $this->discovery->identify(
            $data['ip'],
            (int) ($data['port'] ?? config('devices_zk.port', 4370)),
            $data['comm_key'] ?? null,
            'elle',
        );

        return response()->json($found->toArray());
    }

    /**
     * Bulunan cihazı tek tıkla kaydeder (ya da var olan kaydın bağlantı bilgisini günceller).
     * İki ayrı yerde iki ayrı cihaz kaydı OLUŞMAZ: aynı IP/seri no varsa o kayıt güncellenir.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ip' => ['nullable', 'ip', 'required_if:protokol,zk'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'transport' => ['nullable', Rule::in(['tcp', 'udp'])],
            'comm_key' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
            'protokol' => ['required', Rule::in(array_keys($this->drivers->all()))],
            'ad' => ['required', 'string', 'max:80'],
            'konum' => ['nullable', 'string', 'max:120'],
            'yon' => ['required', Rule::in(['entry', 'exit', 'both'])],
            'tur' => ['nullable', Rule::in(['fingerprint', 'rfid', 'qr', 'face', 'gateway'])],
            'seri_no' => ['nullable', 'string', 'max:80', 'required_if:protokol,adms'],
            'model' => ['nullable', 'string', 'max:60'],
            'marka' => ['nullable', 'string', 'max:40'],
            'yazilim' => ['nullable', 'string', 'max:80'],
        ], [
            'ip.required_if' => 'ZKTeco protokolünde cihazın IP adresi zorunludur.',
            'ip.ip' => 'Geçerli bir IP adresi girin (ör. 192.168.1.50).',
            'ad.required' => 'Cihaza bir ad verin (ör. "Ana giriş parmak izi").',
            'seri_no.required_if' => 'ADMS protokolünde cihazın seri numarası zorunludur (cihaz kendini bununla tanıtır).',
            'comm_key.regex' => 'İletişim şifresi yalnız rakamlardan oluşur.',
        ], ['ad' => 'Cihaz adı', 'konum' => 'Konum', 'yon' => 'Geçiş yönü', 'seri_no' => 'Seri numarası', 'ip' => 'IP adresi']);

        $branchId = app(BranchContext::class)->require();
        $existing = $this->existing($branchId, $data['ip'] ?? null, $data['seri_no'] ?? null);

        if ($existing) {
            $this->fillIdentity($existing, $data);
            $existing->save();

            if (($data['protokol'] ?? 'zk') === 'zk' && ! empty($data['ip'])) {
                $this->zk->saveConnection($existing, [
                    'ip' => $data['ip'],
                    'port' => $data['port'] ?? null,
                    'transport' => $data['transport'] ?? 'tcp',
                ] + (array_key_exists('comm_key', $data) ? ['comm_key' => $data['comm_key']] : []));
            }

            return $this->ok("\"{$existing->name}\" cihazı zaten kayıtlıydı; bağlantı bilgileri güncellendi.", [
                'id' => $existing->id, 'yeni_mi' => false, 'teshis' => $this->diagnostics->forDevice($existing->fresh()),
            ]);
        }

        $device = $this->devices->create([
            'name' => $data['ad'],
            'kind' => $data['tur'] ?? 'fingerprint',
            'location' => $data['konum'] ?? null,
            'direction' => $data['yon'],
            'serial_no' => $data['seri_no'] ?? null,
        ]);

        $this->fillIdentity($device, $data);
        $device->save();

        if (($data['protokol'] ?? 'zk') === 'zk' && ! empty($data['ip'])) {
            $this->zk->saveConnection($device, [
                'ip' => $data['ip'],
                'port' => $data['port'] ?? null,
                'transport' => $data['transport'] ?? 'tcp',
            ] + (array_key_exists('comm_key', $data) ? ['comm_key' => $data['comm_key']] : []));
        }

        if (($data['protokol'] ?? 'zk') === 'adms' && ! empty($data['seri_no'])) {
            $this->adms->forgetUnknown($data['seri_no']);
        }

        return $this->ok("\"{$device->name}\" cihazı eklendi.", [
            'id' => $device->id, 'yeni_mi' => true, 'teshis' => $this->diagnostics->forDevice($device->fresh()),
        ]);
    }

    /** Her cihaz kartının teşhis satırı: bağlı mı, son kayıt, bekleyen, hata + çözüm. */
    public function diagnostics(): JsonResponse
    {
        return response()->json([
            'data' => $this->diagnostics->forBranch(app(BranchContext::class)->require()),
            'ortam' => $this->discovery->environment(),
            'tanitilan_kayitsiz' => $this->adms->unknownDevices(),
        ]);
    }

    private function existing(int $branchId, ?string $ip, ?string $serial): ?Device
    {
        $query = Device::query()->withoutGlobalScope('branch')->where('branch_id', $branchId);

        if ($ip) {
            $byIp = (clone $query)->where('zk_ip', $ip)->first();
            if ($byIp) {
                return $byIp;
            }
        }

        if ($serial) {
            return (clone $query)->whereRaw('UPPER(serial_no) = ?', [mb_strtoupper(trim($serial))])->first();
        }

        return null;
    }

    private function fillIdentity(Device $device, array $data): void
    {
        $device->forceFill(array_filter([
            'protocol' => $data['protokol'] ?? null,
            'device_model' => $data['model'] ?? null,
            'vendor' => $data['marka'] ?? null,
            'firmware' => $data['yazilim'] ?? null,
            'serial_no' => $data['seri_no'] ?? null,
            'location' => $data['konum'] ?? null,
            'discovered_at' => now(),
        ], fn ($v) => $v !== null && $v !== ''));

        $device->forceFill(['name' => $data['ad'], 'direction' => $data['yon']]);
    }
}
