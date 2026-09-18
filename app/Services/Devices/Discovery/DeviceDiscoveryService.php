<?php

namespace App\Services\Devices\Discovery;

use App\Models\Device;
use App\Services\Devices\Zk\Exceptions\ZkException;
use App\Services\Devices\Zk\ZkConnectionSettings;
use App\Services\Devices\Zk\ZkTerminal;

/**
 * AĞ TARAMASI — "cihazı elle IP yazarak aramak" yerine bul, tanı, tek tıkla ekle.
 *
 * Akış:
 *   1. Bu makinenin yerel ağları çıkarılır (NetworkProbe). Yoksa tarama yapılmaz, ekrana
 *      "bu bilgisayar kurumun ağında değil" bilgisi döner.
 *   2. UDP yayın keşfi denenir (ZkBroadcast) — hızlı ama her cihazda çalışmaz.
 *   3. TCP 4370 süpürmesi (PortSweep) — asıl güvenilir yol; /24 ≈ yarım saniye.
 *   4. Açık bulunan her adrese künye sorulur (seri no, model, yazılım, sayaçlar).
 *   5. Zaten kayıtlı cihazlarla eşleştirilir (IP ya da seri no) → "zaten ekli" işareti.
 *
 * Hiçbir adım süresiz beklemez; toplam süre `budget` ile sınırlıdır.
 */
class DeviceDiscoveryService
{
    public function __construct(
        private readonly NetworkProbe $network,
        private readonly PortSweep $sweep,
        private readonly ZkBroadcast $broadcast,
    ) {}

    /** Tarama ekranının açılışta gösterdiği bilgi: taranabilir ağ var mı, hangi düğümdeyiz? */
    public function environment(): array
    {
        $interfaces = $this->network->interfaces();
        $networks = $this->network->defaultNetworks();
        $isLocalNode = config('kurs.node') === 'local';

        return [
            'dugum' => $isLocalNode ? 'local' : 'server',
            'yerel_dugum' => $isLocalNode,
            'taranabilir' => $networks !== [],
            'aglar' => $networks,
            'arayuzler' => $interfaces,
            'varsayilan_port' => (int) config('devices_zk.port', 4370),
            'uyari' => $this->warning($isLocalNode, $networks !== []),
        ];
    }

    /**
     * Taramayı çalıştırır.
     *
     * @param  array{aglar?:list<string>, port?:int, zaman_asimi_ms?:int, sure_sn?:float, yayin?:bool, kunye?:bool, comm_key?:?string}  $options
     */
    public function scan(int $branchId, array $options = []): array
    {
        $port = (int) ($options['port'] ?? config('devices_zk.port', 4370));
        $timeout = min(max((int) ($options['zaman_asimi_ms'] ?? 300), 50), 3000) / 1000;
        $budget = min(max((float) ($options['sure_sn'] ?? 15), 3), 60);
        $networks = $options['aglar'] ?? $this->network->defaultNetworks();
        $started = microtime(true);

        if ($networks === []) {
            return [
                'durum' => 'atlandi',
                'mesaj' => 'Taranacak yerel ağ bulunamadı.',
                'ortam' => $this->environment(),
                'bulunanlar' => [],
                'ozet' => ['taranan_adres' => 0, 'acik_port' => 0, 'sure_ms' => 0],
            ];
        }

        // 1) Yayın keşfi (hızlı, her cihazda çalışmaz)
        $byBroadcast = [];
        if ($options['yayin'] ?? true) {
            $addresses = array_values(array_filter(array_map(fn ($n) => $this->network->broadcastOf($n), $networks)));
            foreach ($this->broadcast->discover($addresses, $port, min(1.2, $budget / 4)) as $hit) {
                $byBroadcast[$hit['ip']] = $hit['yanit_ms'];
            }
        }

        // 2) TCP süpürme (asıl yol)
        $hosts = [];
        foreach ($networks as $network) {
            foreach ($this->network->hostsOf($network) as $host) {
                $hosts[$host] = true;
            }
        }

        $remaining = $budget - (microtime(true) - $started);
        $result = $this->sweep->scan(array_keys($hosts), $port, $timeout, 128, max(2.0, $remaining));

        $candidates = array_values(array_unique(array_merge($result['acik'], array_keys($byBroadcast))));
        sort($candidates, SORT_NATURAL);

        // 3) Künye sorgusu + kayıtlı cihazlarla eşleştirme
        $registered = $this->registeredIndex($branchId);
        $identify = ($options['kunye'] ?? true) && $candidates !== [];
        $devices = [];

        foreach ($candidates as $ip) {
            $left = $budget - (microtime(true) - $started);
            $found = $identify && $left > 1.5
                ? $this->identify($ip, $port, $options['comm_key'] ?? null, isset($byBroadcast[$ip]) ? 'yayin' : 'tarama')
                : new DiscoveredDevice(ip: $ip, port: $port, foundBy: isset($byBroadcast[$ip]) ? 'yayin' : 'tarama');

            $devices[] = $this->attachRegistration($found, $registered);
        }

        return [
            'durum' => 'ok',
            'mesaj' => $devices === []
                ? 'Taranan ağda 4370 portunu açık tutan cihaz bulunamadı.'
                : count($devices).' aday cihaz bulundu.',
            'ortam' => $this->environment(),
            'bulunanlar' => array_map(fn (DiscoveredDevice $d) => $d->toArray(), $devices),
            'ozet' => [
                'aglar' => $networks,
                'port' => $port,
                'taranan_adres' => $result['denenen'],
                'acik_port' => count($result['acik']),
                'yayin_yaniti' => count($byBroadcast),
                'sure_ms' => (int) round((microtime(true) - $started) * 1000),
                'sure_doldu' => $result['kesildi'],
            ],
        ];
    }

    /** Tek adresin künyesini okur. Bağlanamazsa istisna FIRLATMAZ; hatayı Türkçe açıklamayla döner. */
    public function identify(string $ip, int $port = 4370, ?string $commKey = null, string $foundBy = 'tarama'): DiscoveredDevice
    {
        $started = microtime(true);
        $terminal = null;

        try {
            $terminal = ZkTerminal::open(ZkConnectionSettings::fromArray([
                'host' => $ip,
                'port' => $port,
                'transport' => 'tcp',
                'comm_key' => $commKey,
                // Tarama sırasında kısa tutulur: 40 cihazlık bir ağda tek tek 10 sn beklenmez.
                'connect_timeout' => 1.5,
                'read_timeout' => 3.0,
            ]));

            $info = $terminal->info();

            return new DiscoveredDevice(
                ip: $ip, port: $port, transport: 'tcp', protocol: 'zk', identified: true,
                serialNumber: $info->serialNumber,
                model: $info->deviceName,
                platform: $info->platform,
                firmware: $info->firmware,
                macAddress: $info->macAddress,
                userCount: $info->userCount,
                recordCount: $info->recordCount,
                deviceTime: $info->deviceTime,
                responseMs: (int) round((microtime(true) - $started) * 1000),
                foundBy: $foundBy,
            );
        } catch (ZkException $e) {
            return new DiscoveredDevice(
                ip: $ip, port: $port, transport: 'tcp', protocol: 'zk', identified: false,
                responseMs: (int) round((microtime(true) - $started) * 1000),
                errorCode: $e->code(), error: $e->getMessage(), hint: $e->hint, foundBy: $foundBy,
            );
        } finally {
            $terminal?->close();
        }
    }

    /**
     * Şubedeki kayıtlı cihazlar: IP ve seri no ile aranabilen dizin.
     *
     * @return array{ip: array<string, Device>, serial: array<string, Device>}
     */
    private function registeredIndex(int $branchId): array
    {
        $devices = Device::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->get();
        $byIp = [];
        $bySerial = [];

        foreach ($devices as $device) {
            if ($device->zk_ip) {
                $byIp[$device->zk_ip] = $device;
            }
            if ($device->serial_no) {
                $bySerial[mb_strtoupper(trim($device->serial_no))] = $device;
            }
        }

        return ['ip' => $byIp, 'serial' => $bySerial];
    }

    private function attachRegistration(DiscoveredDevice $found, array $registered): DiscoveredDevice
    {
        $device = $registered['ip'][$found->ip] ?? null;

        if (! $device && $found->serialNumber) {
            $device = $registered['serial'][mb_strtoupper(trim($found->serialNumber))] ?? null;
        }

        return $device ? $found->withRegistration($device->id, $device->name) : $found;
    }

    private function warning(bool $isLocalNode, bool $scannable): ?string
    {
        if ($scannable && $isLocalNode) {
            return null;
        }

        if (! $isLocalNode) {
            return 'Bu ekran WEB SUNUCUSUNDA açıldı. Sunucu kurumun yerel ağına giremez; '
                .'parmak izi terminalleri ancak kurumdaki bilgisayarda kurulu masaüstü uygulamasından taranabilir. '
                .'Taramayı kurumdaki bilgisayardan yapın (Yoklama › Cihazlar › Cihaz bul).';
        }

        return 'Bu bilgisayarda taranabilecek bir yerel ağ bulunamadı. '
            .'Wifi/kablo bağlantısının açık ve modemle aynı ağda olduğundan emin olun.';
    }
}
