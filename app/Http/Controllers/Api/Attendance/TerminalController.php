<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Services\Devices\Discovery\NetworkProbe;
use App\Services\Devices\Drivers\DriverRegistry;
use App\Services\Devices\Drivers\TerminalDriver;
use App\Services\Devices\Network\RawTcpDiagnostic;
use App\Services\Devices\Network\TcpProbe;
use App\Services\Devices\Terminal\TerminalConnectionTester;
use App\Services\Devices\Terminal\TerminalEndpoint;
use App\Services\Devices\Terminal\PushListener;
use App\Services\Devices\Terminal\TerminalPacketStore;
use App\Services\Devices\Terminal\TerminalStateStore;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * TERMİNAL KÖPRÜSÜ (sürücü bağımsız) — docs/CIHAZ-KOPRUSU.md
 *
 * Ayar/test/ham tanılama uçları YALNIZ masaüstü yerel düğümde çalışır (`terminal.desktop` ara katmanı;
 * sunucu 192.168.x.x gibi özel adreslere ASLA bağlanmaya çalışmaz). Okuma uçları sır içermez.
 * Yetki: `devices.manage`.
 */
class TerminalController extends ApiController
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly TerminalConnectionTester $tester,
        private readonly TerminalStateStore $state,
        private readonly TerminalPacketStore $packets,
    ) {}

    public function drivers(): JsonResponse
    {
        return response()->json(['data' => array_values(array_filter(
            $this->drivers->catalog(),
            fn (array $d) => $this->drivers->terminal($d['anahtar']) !== null,
        ))]);
    }

    /** Cihazın terminal ayarı + bu düğümdeki son durum (sır yok). */
    public function show(Device $device): JsonResponse
    {
        return response()->json($this->describe($device));
    }

    public function save(Request $request, Device $device): JsonResponse
    {
        $data = $this->validateSettings($request, true);
        $driver = $this->driverOrFail($data['surucu']);

        $device->fill([
            'protocol' => $driver->key(),
            'zk_ip' => $data['ip'],
            'zk_port' => (int) ($data['port'] ?? $driver->defaultPort() ?? 0),
            'zk_transport' => in_array($data['transport'] ?? 'tcp', $driver->transports(), true) ? ($data['transport'] ?? 'tcp') : $driver->transports()[0],
            'vendor' => $data['marka'] ?? null,
            'device_model' => $data['model'] ?? null,
            'machine_no' => (int) ($data['makine_id'] ?? 1),
            'terminal_connection' => $data['baglanti_tipi'] ?? 'pull',
        ]);

        // Alan gönderilmediyse kayıtlı şifre korunur; boş gönderildiyse kaldırılır. Günlüğe asla yazılmaz.
        if (array_key_exists('comm_key', $data)) {
            $device->zk_comm_key = $data['comm_key'] === '' || $data['comm_key'] === null ? null : $data['comm_key'];
        }

        $device->save();

        // "Server / Push" seçildiyse dinleyici kendiliğinden açılır (kullanıcı ayrıca Push sekmesine gitmek zorunda değil).
        if (($data['baglanti_tipi'] ?? null) === 'push' && ! $this->state->pushSettings()['acik']) {
            $p = $this->state->pushSettings();
            $this->state->putPushSettings(true, $p['port'], $p['aktar_ip'], $p['aktar_port']);
        }

        Audit::log('device.terminal_settings_saved', "\"{$device->name}\" terminalinin bağlantı ayarını güncelledi ({$driver->label()}, #{$device->id}).", $device);

        return $this->ok('Terminal ayarı kaydedildi.', ['cihaz' => $this->describe($device->fresh())]);
    }

    /** İki aşamalı test: kayıtlı cihaz için ya da formdaki (henüz kaydedilmemiş) değerlerle. */
    public function test(Request $request, ?Device $device = null): JsonResponse
    {
        if ($device && ! $request->filled('ip')) {
            $driver = $this->drivers->terminal($device->protocol ?: 'zk');
            if (! $driver) {
                return response()->json(['durum' => 'hata', 'kod' => 'desteklenmiyor', 'mesaj' => 'Bu cihazın sürücüsü ağ üzerinden test edilemez.'], 422);
            }
            if (! $device->zk_ip) {
                return response()->json(['durum' => 'hata', 'kod' => 'eksik', 'mesaj' => 'Cihazın IP adresi girilmemiş.', 'oneri' => 'Önce IP adresini ve portu kaydedin.'], 422);
            }

            return response()->json($this->tester->run($driver, TerminalEndpoint::fromDevice($device, $driver->defaultPort() ?? 0))->toArray());
        }

        $data = $this->validateSettings($request, false);
        $driver = $this->driverOrFail($data['surucu']);
        $commKey = array_key_exists('comm_key', $data) ? ($data['comm_key'] ?: null) : $device?->zk_comm_key;

        $endpoint = new TerminalEndpoint(
            host: $data['ip'],
            port: (int) ($data['port'] ?? $driver->defaultPort() ?? 0),
            transport: in_array($data['transport'] ?? 'tcp', $driver->transports(), true) ? ($data['transport'] ?? 'tcp') : 'tcp',
            commKey: $commKey,
            connectTimeout: (float) config('devices_zk.connect_timeout', 3),
            readTimeout: (float) config('devices_zk.read_timeout', 10),
            deviceId: $device?->id,
            machineId: (int) ($data['makine_id'] ?? $device?->machine_no ?? 1),
        );

        return response()->json($this->tester->run($driver, $endpoint)->toArray());
    }

    /** Ham TCP tanılaması (geliştirici modu). Varsayılan: hiçbir şey göndermez, yalnız dinler. */
    public function rawDiagnostic(Request $request, RawTcpDiagnostic $diagnostic): JsonResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'gonderilecek_hex' => ['nullable', 'string', 'max:4000'],
            'dinleme_sn' => ['nullable', 'numeric', 'between:0.5,30'],
            'cihaz_id' => ['nullable', 'integer'],
        ], [
            'ip.required' => 'IP adresi zorunludur.',
            'ip.ip' => 'Geçerli bir IP adresi girin (ör. 192.168.1.50).',
            'port.required' => 'Port zorunludur.',
            'port.between' => 'Port 1 ile 65535 arasında olmalıdır.',
            'dinleme_sn.between' => 'Dinleme süresi 0,5 ile 30 saniye arasında olmalıdır.',
        ], ['ip' => 'IP adresi', 'port' => 'Port', 'gonderilecek_hex' => 'Gönderilecek HEX', 'dinleme_sn' => 'Dinleme süresi']);

        // Bayt GÖNDERMEK geliştirici modu ister; yalnız dinlemek her zaman serbest.
        if (trim((string) ($data['gonderilecek_hex'] ?? '')) !== '' && ! $this->state->developerMode()) {
            return response()->json(['message' => 'Ham bayt göndermek için Terminal Teşhis › Geliştirici modu açılmalıdır. Yalnız dinleme için HEX alanını boş bırakın.', 'error_code' => 'terminal_dev_mode_required'], 403);
        }

        $device = ! empty($data['cihaz_id']) ? Device::query()->withoutGlobalScope('branch')->find((int) $data['cihaz_id']) : null;
        $result = $diagnostic->run($data['ip'], (int) $data['port'], $data['gonderilecek_hex'] ?? null, (float) ($data['dinleme_sn'] ?? 5), 3.0, $device?->id, $device?->protocol === 'perkotek_fk' ? 'YT33' : 'TERMINAL');

        if (($result['mesaj'] ?? null) && ! isset($result['soket'])) {
            return response()->json(['message' => $result['mesaj'], 'errors' => ['gonderilecek_hex' => [$result['mesaj']]]], 422);
        }

        $record = $this->state->pushDiagnostic($result + ['cihaz_id' => $data['cihaz_id'] ?? null]);

        if (! empty($data['cihaz_id']) && $result['soket']['baglandi']) {
            $this->state->putDevice((int) $data['cihaz_id'], ['kopru_ip' => $result['soket']['yerel_ip'], 'son_paket' => [
                'zaman' => $result['baslangic'], 'bayt' => $result['alinan_bayt'], 'hex' => mb_substr($result['hex'], 0, 400),
            ]]);
        }

        return response()->json($record);
    }

    /** Son ham tanılamanın .txt / .hex indirmesi. */
    public function downloadDiagnostic(Request $request, string $id): Response
    {
        $row = $this->state->findDiagnostic($id);
        abort_if($row === null, 404, 'Tanılama kaydı bulunamadı.');

        $hex = $request->query('bicim') === 'hex';
        $body = $hex ? ($row['hex'] ?? '') : $this->diagnosticText($row);
        $name = 'terminal-tani-'.preg_replace('/[^0-9A-Za-z.-]/', '_', (string) ($row['hedef'] ?? 'cihaz')).'-'.$id.($hex ? '.hex' : '.txt');

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /** TERMİNAL TEŞHİS — tek bakış (bu düğüm). */
    public function diagnostics(NetworkProbe $network, TcpProbe $probe): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $devices = Device::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
            ->whereIn('kind', ['fingerprint', 'face', 'rfid'])->orderBy('name')->get();

        $last = AttendanceEvent::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
            ->whereNotNull('device_id')->latest('occurred_at')->first(['occurred_at', 'device_id', 'raw_identifier', 'event_type', 'is_matched']);

        $pendingMatch = AttendanceEvent::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->where('is_matched', false)->count();

        return response()->json([
            'isletim_sistemi' => PHP_OS_FAMILY,
            'macos' => $probe->isMac(),
            'arayuzler' => $network->interfaces(),
            'cihazlar' => $devices->map(fn (Device $d) => $this->describe($d))->values(),
            'son_yoklama' => $last ? ['zaman' => $last->occurred_at, 'cihaz_id' => $last->device_id, 'kullanici_no' => $last->raw_identifier, 'yon' => $last->event_type, 'eslesti' => (bool) $last->is_matched] : null,
            'bekleyen_eslesme' => $pendingMatch,
            'bekleyen_esitleme' => $this->pendingSync(),
            'push_dinleyici' => $this->pushStatus($network),
            'push_paketleri' => $this->packets->latest(50),
            'ham_tanilamalar' => $this->state->diagnostics(),
        ]);
    }

    // ------------------------------------------------------------------ push dinleyicisi

    public function push(NetworkProbe $network): JsonResponse
    {
        return response()->json($this->pushStatus($network));
    }

    /** Push ayarı: aç/kapat, port, isteğe bağlı aktarma hedefi. Cihazın kendi ayarı OTOMATİK değiştirilmez. */
    public function savePush(Request $request, NetworkProbe $network): JsonResponse
    {
        $data = $request->validate([
            'acik' => ['required', 'boolean'],
            'port' => ['required', 'integer', 'between:1024,65535'],
            'aktar_ip' => ['nullable', 'ip'],
            'aktar_port' => ['nullable', 'required_with:aktar_ip', 'integer', 'between:1,65535'],
        ], [
            'port.between' => 'Port 1024 ile 65535 arasında olmalıdır (önerilen 7005).',
            'aktar_ip.ip' => 'Aktarma adresi geçerli bir IP olmalıdır (ör. 192.168.68.5).',
            'aktar_port.required_with' => 'Aktarma için port da girilmelidir.',
            'aktar_port.between' => 'Aktarma portu 1 ile 65535 arasında olmalıdır.',
        ], ['acik' => 'Push dinleyicisi', 'port' => 'Port', 'aktar_ip' => 'Aktarma IP', 'aktar_port' => 'Aktarma portu']);

        $this->state->putPushSettings((bool) $data['acik'], (int) $data['port'], $data['aktar_ip'] ?? null, isset($data['aktar_port']) ? (int) $data['aktar_port'] : null);

        Audit::log('device.terminal_push_saved', 'Terminal push dinleyicisi ayarını güncelledi ('.($data['acik'] ? 'açık' : 'kapalı').', port '.$data['port'].(! empty($data['aktar_ip']) ? ', aktarma '.$data['aktar_ip'].':'.$data['aktar_port'] : '').').');

        return $this->ok($data['acik'] ? 'Push dinleyicisi birkaç saniye içinde başlar.' : 'Push dinleyicisi kapatıldı.', ['push' => $this->pushStatus($network)]);
    }

    /**
     * "Dinlemeyi başlat": ayarı açar, dinleyici çalışmıyorsa bu makinede hemen başlatır (masaüstü denetçisini
     * beklemeden), ≤5 sn içinde 127.0.0.1:<port> iç bağlantısıyla GERÇEKTEN dinlendiğini doğrular.
     */
    public function startPush(NetworkProbe $network): JsonResponse
    {
        $p = $this->state->pushSettings();
        $this->state->putPushSettings(true, $p['port'], $p['aktar_ip'], $p['aktar_port']);

        $spawned = false;
        if (! $this->portListening($p['port'])) {
            $spawned = $this->spawnListener();
        }

        $deadline = microtime(true) + 5.0;
        while (! ($ok = $this->portListening($p['port'])) && microtime(true) < $deadline) {
            usleep(250000);
            if (($this->state->listenerState()['durum'] ?? null) === 'hata') {
                break;
            }
        }

        Audit::log('device.terminal_push_started', "Push dinleyicisini başlattı (port {$p['port']}).");
        $status = $this->pushStatus($network);

        return response()->json([
            'basarili' => $ok,
            'message' => $ok ? "Dinleniyor: 0.0.0.0:{$p['port']} (iç bağlantı testi başarılı)." : ($status['durum'] === 'hata' ? $status['durum_metni'] : 'Dinleyici 5 sn içinde başlamadı.'.($spawned ? '' : ' Başlatma komutu çalıştırılamadı.')),
            'push' => $status,
        ], $ok ? 200 : 422);
    }

    public function stopPush(NetworkProbe $network): JsonResponse
    {
        $p = $this->state->pushSettings();
        $this->state->putPushSettings(false, $p['port'], $p['aktar_ip'], $p['aktar_port']);

        $pid = (int) ($this->state->listenerState()['pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        }

        $deadline = microtime(true) + 5.0;
        while ($this->portListening($p['port']) && microtime(true) < $deadline) {
            usleep(250000);
        }

        Audit::log('device.terminal_push_stopped', 'Push dinleyicisini durdurdu.');

        return $this->ok($this->portListening($p['port']) ? 'Durdurma istendi; birkaç saniye içinde kapanır.' : 'Push dinleyicisi durduruldu.', ['push' => $this->pushStatus($network)]);
    }

    /** Bu makinede port gerçekten dinleniyor mu? (127.0.0.1'e 0,3 sn'lik bağlantı; dinleyici bunu kaydetmez) */
    private function portListening(int $port): bool
    {
        $s = @stream_socket_client("tcp://127.0.0.1:{$port}", $e, $m, 0.3);
        if ($s === false) {
            return false;
        }
        @fclose($s);

        return true;
    }

    /**
     * Dinleyiciyi arka planda başlatır (kabuk "&" ile ayrılır; istek beklemez). Süreç yerel sunucunun süreç grubunda
     * kalır → uygulama kapanınca onunla kapanır. Kilit dosyası ikinci kopyayı engeller.
     */
    private function spawnListener(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $log = storage_path('logs/terminal-dinleyici.log');
        $cmd = sprintf('%s %s kurs:terminal-dinle --no-ansi --no-interaction >> %s 2>&1 < /dev/null &',
            escapeshellarg(PHP_BINARY ?: 'php'), escapeshellarg(base_path('artisan')), escapeshellarg($log));
        @exec($cmd, $out, $code);

        return $code === 0;
    }

    public function packets(): JsonResponse
    {
        return response()->json(['data' => $this->packets->latest(50), 'toplam' => $this->packets->count()]);
    }

    public function packet(int $id): JsonResponse
    {
        $detail = $this->packets->detail($id);
        abort_if($detail === null, 404, 'Paket bulunamadı.');

        return response()->json($detail + ['yon_metni' => TerminalPacketStore::directionLabel($detail['yon'], $detail['aktarma'])]);
    }

    public function downloadPacket(Request $request, int $id): Response
    {
        $detail = $this->packets->detail($id);
        abort_if($detail === null, 404, 'Paket bulunamadı.');
        $hex = $request->query('bicim') === 'hex';

        return response($hex ? $detail['hex'] : $this->packets->text($detail), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="terminal-paket-'.$id.($hex ? '.hex' : '.txt').'"',
        ]);
    }

    private function pushStatus(NetworkProbe $network): array
    {
        $settings = $this->state->pushSettings();
        $live = $this->state->listenerState();
        $beat = isset($live['kalp']) ? now()->diffInSeconds(\Carbon\Carbon::parse($live['kalp']), true) : null;

        $status = match (true) {
            ! $this->state->pushWanted() => 'kapali',
            ($live['durum'] ?? null) === 'hata' => 'hata',
            ($live['durum'] ?? null) === 'aktif' && $beat !== null && $beat <= 20 && (int) ($live['port'] ?? 0) === $settings['port'] => 'aktif',
            default => 'baslatiliyor',
        };

        $bridgeIp = $this->bridgeIp($network);

        return [
            'durum' => $status,
            'durum_metni' => match ($status) {
                'aktif' => 'Aktif · port '.$settings['port'],
                'kapali' => 'Kapalı',
                'hata' => (string) ($live['hata'] ?? 'Dinleyici açılamadı.'),
                default => 'Başlatılıyor… (birkaç saniye sürer; başlamazsa uygulamayı yeniden açın)',
            },
            'oneri' => $status === 'hata' ? ($live['oneri'] ?? null) : null,
            'acik' => $settings['acik'],
            'port' => $settings['port'],
            'aktar_ip' => $settings['aktar_ip'],
            'aktar_port' => $settings['aktar_port'],
            'aktarma_hatasi' => $status === 'aktif' ? ($live['aktarma_hatasi'] ?? null) : null,
            'son_ip' => $live['son_ip'] ?? null,
            'son_zaman' => $live['son_zaman'] ?? null,
            'baglanti_sayisi' => (int) ($live['baglanti_sayisi'] ?? 0),
            'paket_sayisi' => $this->packets->count(),
            'okutma_sayisi' => (int) ($live['okutma_sayisi'] ?? 0),   // bu oturumda işlenen okutma (yoklama/PDKS)
            'son_okutma' => $live['son_okutma'] ?? null,
            'kalp' => $live['kalp'] ?? null,
            'calisiyor' => $status === 'aktif',
            'port_dinleniyor' => $this->portListening($settings['port']),
            'istenen' => $this->state->pushWanted(),
            'denetci' => $this->state->supervisorState(),
            'dinleme_ip' => '0.0.0.0',
            'rx_bayt' => (int) ($live['rx_bayt'] ?? 0),
            'tx_bayt' => (int) ($live['tx_bayt'] ?? 0),
            'son_veri' => $live['son_veri'] ?? null,
            'acik_baglanti' => (int) ($live['acik_baglanti'] ?? 0),
            'terminaller' => \Illuminate\Support\Facades\Schema::hasTable('terminal_raw_packets')
                ? \Illuminate\Support\Facades\DB::table('terminal_raw_packets')->where('direction', '!=', 'upstream_to_device')
                    ->selectRaw('remote_ip, MAX(received_at) AS son, COUNT(*) AS paket, SUM(byte_count) AS bayt')->groupBy('remote_ip')->orderByDesc('son')->limit(20)->get()
                : [],
            'kopru_ip' => $bridgeIp,
            // Cihaz menüsüne kullanıcının ELLE yazacağı değerler (uygulama cihaz ayarını değiştirmez)
            'cihaz_menusu' => $bridgeIp ? ['server_ip' => $bridgeIp, 'push_address' => $bridgeIp, 'port' => $settings['port'], 'push' => 'Open'] : null,
        ];
    }

    /** Köprü IP'si: son soket testinin yerel kaynak IP'si; yoksa bu makinenin ilk özel ağ adresi. */
    private function bridgeIp(NetworkProbe $network): ?string
    {
        $latest = null;
        foreach (Device::query()->withoutGlobalScope('branch')->pluck('id') as $id) {
            $d = $this->state->device((int) $id);
            if (! empty($d['kopru_ip']) && ($latest === null || ($d['son_test'] ?? '') > ($latest['son_test'] ?? ''))) {
                $latest = $d;
            }
        }

        if ($latest && $latest['kopru_ip'] !== '127.0.0.1') {
            return $latest['kopru_ip'];
        }

        foreach ($network->interfaces() as $iface) {
            if ($iface['taranabilir']) {
                return $iface['ip'];
            }
        }

        return $latest['kopru_ip'] ?? null;
    }

    // ------------------------------------------------------------------ yardımcılar

    private function describe(Device $device): array
    {
        $driver = $this->drivers->terminal($device->protocol ?: 'zk');
        $state = $this->state->device($device->id);

        return [
            'id' => $device->id,
            'ad' => $device->name,
            'tur' => $device->kind,
            'konum' => $device->location,
            'surucu' => $driver?->key() ?? $device->protocol,
            'surucu_etiketi' => $driver?->label() ?? $this->drivers->find($device->protocol)?->label(),
            'protokol_dogrulandi' => $driver?->protocolVerified() ?? false,
            'marka' => $device->vendor,
            'model' => $device->device_model,
            'ip' => $device->zk_ip,
            'port' => $device->zk_port,
            'aktarim' => $device->zk_transport,
            'makine_id' => (int) ($device->machine_no ?: 1),
            'baglanti_tipi' => $device->terminal_connection ?: 'pull',
            'sifre_tanimli' => $device->zk_comm_key !== null,
            'son_cekme' => $device->zk_last_pull_at,
            'son_cekme_durumu' => $device->zk_last_status,
            'son_cekme_hatasi' => $device->zk_last_error,
            'durum' => [
                'kopru_ip' => $state['kopru_ip'] ?? null,
                'tcp' => $state['tcp_durum'] ?? null,
                'tcp_sure_ms' => $state['tcp_sure_ms'] ?? null,
                'protokol' => $state['protokol_durum'] ?? null,
                'son_test' => $state['son_test'] ?? null,
                'son_test_durum' => $state['son_test_durum'] ?? null,
                'son_hata' => $state['son_hata'] ?? null,
                'macos_yerel_ag_izni_olasi' => $state['macos_yerel_ag_izni_olasi'] ?? false,
                'son_paket' => $state['son_paket'] ?? null,
            ],
        ];
    }

    private function validateSettings(Request $request, bool $saving): array
    {
        return $request->validate([
            'surucu' => ['required', Rule::in(array_keys(array_filter($this->drivers->all(), fn ($d) => $d instanceof TerminalDriver)))],
            'ip' => ['required', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'transport' => ['nullable', Rule::in(['tcp', 'udp'])],
            // Yalnız rakam: cihazın web arayüzü şifresi (ör. admin) iletişim şifresi olarak KABUL EDİLMEZ.
            'comm_key' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
            'marka' => [$saving ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:40'],
            'makine_id' => ['nullable', 'integer', 'between:1,65535'],
            'baglanti_tipi' => ['nullable', Rule::in(['pull', 'push'])],
            'model' => [$saving ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:60'],
        ], [
            'surucu.required' => 'Sürücü seçilmelidir.',
            'surucu.in' => 'Geçersiz sürücü.',
            'ip.required' => 'Cihazın IP adresi zorunludur.',
            'ip.ip' => 'Geçerli bir IP adresi girin (ör. 192.168.1.50).',
            'port.between' => 'Port 1 ile 65535 arasında olmalıdır.',
            'makine_id.between' => 'Cihaz / Machine ID 1 ile 65535 arasında olmalıdır.',
            'baglanti_tipi.in' => 'Bağlantı tipi "LAN / TCP Pull" ya da "Server / Push" olmalıdır.',
            'comm_key.regex' => 'İletişim şifresi yalnız rakamlardan oluşur (cihazın web arayüzü şifresi değildir).',
            'comm_key.max' => 'İletişim şifresi en çok 20 hane olabilir.',
        ], ['surucu' => 'Sürücü', 'ip' => 'IP adresi', 'port' => 'Port', 'transport' => 'Bağlantı türü', 'comm_key' => 'İletişim şifresi', 'marka' => 'Üretici', 'model' => 'Model']);
    }

    private function driverOrFail(string $key): TerminalDriver
    {
        return $this->drivers->terminal($key) ?? abort(422, 'Geçersiz sürücü.');
    }

    /** Sunucuya henüz gönderilmemiş yerel değişiklik sayısı (yalnız yerel düğümde anlamlı). */
    private function pendingSync(): ?int
    {
        if (config('kurs.node') !== 'local') {
            return null;
        }

        try {
            return app(\App\Sync\Local\LocalState::class)->pendingCount();
        } catch (\Throwable) {
            return null;
        }
    }

    private function diagnosticText(array $row): string
    {
        $lines = [
            'Erbaa Kurs — Ham TCP tanılaması',
            'Başlangıç     : '.($row['baslangic'] ?? ''),
            'Hedef         : '.($row['hedef'] ?? '').'/tcp',
            'Çözümleme     : '.($row['cozumleme'] ?? ''),
            'Bağlandı      : '.(($row['soket']['baglandi'] ?? false) ? 'evet' : 'hayır').' ('.($row['soket']['sure_ms'] ?? '?').' ms)',
            'Yerel uç      : '.($row['soket']['yerel_ip'] ?? '-').':'.($row['soket']['yerel_port'] ?? '-'),
            'Hata          : '.($row['soket']['hata_no'] ?? '-').' '.($row['soket']['hata'] ?? ''),
            'Gönderilen    : '.($row['gonderilen_bayt'] ?? 0).' bayt',
            'Alınan        : '.($row['alinan_bayt'] ?? 0).' bayt',
            'Kapanış       : '.($row['kapanis_metni'] ?? ''),
            '',
            '--- Zaman çizelgesi',
        ];

        foreach ($row['zaman_cizelgesi'] ?? [] as $e) {
            $lines[] = sprintf('%6d ms  %s', $e['t_ms'], $e['olay']);
        }

        if (! empty($row['gonderilen_hex'])) {
            $lines[] = '';
            $lines[] = '--- Gönderilen (HEX)';
            $lines[] = $row['gonderilen_hex'];
        }

        $lines[] = '';
        $lines[] = '--- Alınan (HEX)';
        $lines[] = ($row['hex'] ?? '') !== '' ? $row['hex'] : '(boş)';

        return implode("\n", $lines)."\n";
    }
}
