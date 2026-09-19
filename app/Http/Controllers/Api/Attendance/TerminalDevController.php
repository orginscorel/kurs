<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Device;
use App\Services\Devices\Analysis\HarImporter;
use App\Services\Devices\Analysis\PacketAnalyzer;
use App\Services\Devices\Network\RawTcpDiagnostic;
use App\Services\Devices\Network\TcpSession;
use App\Services\Devices\Terminal\TerminalStateStore;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * GELİŞTİRİCİ ARAÇLARI (yalnız masaüstü, devices.manage + ayrı "geliştirici modu" anahtarı):
 *   • HAM TCP OTURUMU / MANUEL HEX GÖNDERİCİ — kullanıcının yazdığı baytlar, tek bağlantıda sırayla.
 *   • RAW TCP LOG — tüm TX/RX (kopyala / indir / temizle, maskeleme seçenekli).
 *   • PROTOKOL ANALİZİ — örnek kaydı, karşılaştırma (tahmin; sürücüye otomatik aktarılmaz), HAR içe aktarma.
 * Uygulama hiçbir zaman kendiliğinden paket üretip göndermez.
 */
class TerminalDevController extends ApiController
{
    private const DEV_MESSAGE = 'Bu araç yalnız geliştirici modunda kullanılabilir. Terminal Teşhis › Geliştirici modu anahtarını açın.';

    public function __construct(private readonly TerminalStateStore $state) {}

    public function mode(): JsonResponse
    {
        return response()->json(['acik' => $this->state->developerMode()]);
    }

    public function setMode(Request $request): JsonResponse
    {
        $on = (bool) $request->validate(['acik' => ['required', 'boolean']])['acik'];
        $this->state->setDeveloperMode($on);
        Audit::log('device.terminal_dev_mode', 'Terminal geliştirici modunu '.($on ? 'açtı' : 'kapattı').'.');

        return $this->ok($on ? 'Geliştirici modu açıldı.' : 'Geliştirici modu kapatıldı.', ['acik' => $on]);
    }

    /**
     * Ham TCP oturumu. Paket yoksa yalnız dinler (geliştirici modu gerekmez); paket varsa geliştirici modu şart.
     * "paketler": her satır bir paket (HEX; boşluk/virgül serbest). Aynı bağlantıda sırayla gönderilir.
     */
    public function session(Request $request, TcpSession $session): JsonResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'paketler' => ['nullable', 'string', 'max:20000'],
            'yanit_bekleme_sn' => ['nullable', 'numeric', 'between:0.2,30'],
            'once_dinle_sn' => ['nullable', 'numeric', 'between:0,30'],
            'cihaz_id' => ['nullable', 'integer'],
        ], [
            'ip.ip' => 'Geçerli bir IP adresi girin (ör. 192.168.1.50).',
            'port.between' => 'Port 1 ile 65535 arasında olmalıdır.',
        ], ['ip' => 'IP adresi', 'port' => 'Port', 'paketler' => 'HEX paketler']);

        $packets = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($data['paketler'] ?? '')) ?: [] as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $bytes = RawTcpDiagnostic::parseHex($line);
            if ($bytes === false) {
                return response()->json(['message' => 'Satır '.($i + 1).': geçersiz HEX. Yalnız 0-9 A-F kullanın; her bayt iki hane (ör. "A5 5A 01 00"). Boşluk ve virgül serbest.', 'errors' => ['paketler' => ['Satır '.($i + 1).': geçersiz HEX.']]], 422);
            }
            $packets[] = $bytes;
        }

        if ($packets !== [] && ! $this->state->developerMode()) {
            return response()->json(['message' => self::DEV_MESSAGE, 'error_code' => 'terminal_dev_mode_required'], 403);
        }

        $device = ! empty($data['cihaz_id']) ? Device::query()->withoutGlobalScope('branch')->find((int) $data['cihaz_id']) : null;
        $tag = $device?->protocol === 'perkotek_fk' ? 'YT33' : 'TERMINAL';
        $wait = (float) ($data['yanit_bekleme_sn'] ?? 3);
        $listen = (float) ($data['once_dinle_sn'] ?? ($packets === [] ? $wait : 0));

        $result = $session->run($data['ip'], (int) $data['port'], $packets, $wait, $listen, $device?->id, $tag);

        if ($device && $result['soket']['baglandi']) {
            $this->state->putDevice($device->id, ['kopru_ip' => $result['soket']['yerel_ip'], 'son_oturum' => [
                'zaman' => $result['bitis'], 'son_durum' => $result['son_durum'], 'tx' => $result['tx_bayt'], 'rx' => $result['rx_bayt'],
                'yerel' => $result['soket']['yerel_ip'].':'.$result['soket']['yerel_port'], 'uzak' => $result['hedef'],
                'sure_ms' => $result['sure_ms'], 'zaman_asimi_sn' => $result['zaman_asimi_sn'],
            ]]);
        }

        if ($packets !== []) {
            Audit::log('device.terminal_hex_sent', count($packets)." ham paket gönderildi → {$result['hedef']} (geliştirici modu).");
        }

        return response()->json($result);
    }

    public function rawLog(Request $request): JsonResponse
    {
        $rows = DB::table('terminal_tcp_log')->orderByDesc('id')->limit(min(2000, max(50, (int) $request->query('limit', 500))))->get();

        return response()->json(['data' => $rows->map(fn ($r) => (array) $r + ['ascii' => $r->payload_hex ? RawTcpDiagnostic::ascii((string) hex2bin($r->payload_hex)) : null])->all()]);
    }

    /** RAW log metni (kopyala/indir). Maskeleme: IP adresleri ve iletişim şifresi baytları (en iyi çaba). */
    public function rawLogExport(Request $request): Response
    {
        $maskIp = $request->boolean('maske_ip');
        $maskKey = $request->boolean('maske_sifre', true);
        $keys = [];

        if ($maskKey) {
            foreach (Device::query()->withoutGlobalScope('branch')->whereNotNull('zk_comm_key_encrypted')->get() as $d) {
                $k = (string) $d->zk_comm_key;
                if ($k !== '' && $k !== '0') {
                    $keys[] = bin2hex($k);                                 // ASCII rakamlar
                    if (ctype_digit($k) && (int) $k > 0) {
                        $keys[] = bin2hex(pack('V', (int) $k));            // 32 bit LE sayı
                        $keys[] = bin2hex(pack('N', (int) $k));            // 32 bit BE sayı
                    }
                }
            }
        }

        $lines = ['# Erbaa Kurs — RAW TCP log · '.now()->toIso8601String().($maskIp ? ' · IP maskeli' : '').($keys ? ' · iletişim şifresi maskeli' : '')];
        $rows = DB::table('terminal_tcp_log')->orderBy('id')->limit(20000)->get();

        foreach ($rows as $r) {
            $hex = (string) $r->payload_hex;
            foreach ($keys as $k) {
                $hex = str_replace($k, str_repeat('**', intdiv(strlen($k), 2)), $hex);
            }
            $src = $maskIp ? $this->maskIp((string) $r->source) : $r->source;
            $dst = $maskIp ? $this->maskIp((string) $r->target) : $r->target;
            $lines[] = $r->kind === 'STATE'
                ? sprintf('%s [%s] +%dms STATE %s%s', $r->occurred_at, $r->session_id, $r->t_ms, $r->state, $r->note ? ' — '.($maskIp ? $this->maskIp($r->note) : $r->note) : '')
                : sprintf('%s [%s] +%dms %s %s→%s len=%d hex=%s ascii=%s', $r->occurred_at, $r->session_id, $r->t_ms, $r->kind, $src, $dst, $r->length,
                    trim(chunk_split($hex, 2, ' ')), str_contains($hex, '*') ? '(maskeli)' : RawTcpDiagnostic::ascii((string) hex2bin($hex)));
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="terminal-raw-log-'.now()->format('Ymd-His').'.txt"',
        ]);
    }

    public function clearRawLog(): JsonResponse
    {
        $n = DB::table('terminal_tcp_log')->delete();
        Audit::log('device.terminal_raw_log_cleared', "RAW TCP logu temizlendi ({$n} satır).");

        return $this->ok("RAW log temizlendi ({$n} satır).");
    }

    // ------------------------------------------------------------ protokol analizi

    public function samples(): JsonResponse
    {
        if ($r = $this->needDev()) {
            return $r;
        }

        return response()->json(['data' => DB::table('terminal_protocol_samples')->orderByDesc('id')->limit(500)->get()]);
    }

    public function storeSample(Request $request, ?int $id = null): JsonResponse
    {
        if ($r = $this->needDev()) {
            return $r;
        }

        $data = $request->validate([
            'ad' => ['required', 'string', 'max:120'],
            'tx_hex' => ['nullable', 'string', 'max:2000000'],
            'rx_hex' => ['nullable', 'string', 'max:2000000'],
            'not' => ['nullable', 'string', 'max:5000'],
            'kaynak' => ['nullable', Rule::in(['uygulama', 'wireshark', 'har', 'elle'])],
            'tcp_log_id' => ['nullable', 'integer'],
        ], ['ad.required' => 'İşlem adı zorunludur.']);

        foreach (['tx_hex', 'rx_hex'] as $f) {
            if (! empty($data[$f]) && RawTcpDiagnostic::parseHex($data[$f]) === false) {
                return response()->json(['message' => strtoupper(substr($f, 0, 2)).' HEX geçersiz.', 'errors' => [$f => ['Geçersiz HEX.']]], 422);
            }
        }

        $row = [
            'name' => $data['ad'],
            'tx_hex' => ! empty($data['tx_hex']) ? bin2hex((string) RawTcpDiagnostic::parseHex($data['tx_hex'])) : null,
            'rx_hex' => ! empty($data['rx_hex']) ? bin2hex((string) RawTcpDiagnostic::parseHex($data['rx_hex'])) : null,
            'note' => $data['not'] ?? null,
            'source' => $data['kaynak'] ?? 'elle',
            'tcp_log_id' => $data['tcp_log_id'] ?? null,
            'updated_at' => now(),
        ];

        if ($id) {
            DB::table('terminal_protocol_samples')->where('id', $id)->update($row);
        } else {
            $id = (int) DB::table('terminal_protocol_samples')->insertGetId($row + ['created_at' => now()]);
        }

        return $this->ok('Örnek kaydedildi.', ['id' => $id]);
    }

    /** RAW log satırından analize ekle (TX ya da RX satırı). */
    public function sampleFromLog(Request $request, int $logId): JsonResponse
    {
        if ($r = $this->needDev()) {
            return $r;
        }

        $log = DB::table('terminal_tcp_log')->find($logId);
        abort_if(! $log || ! in_array($log->kind, ['TX', 'RX'], true), 404, 'Log satırı bulunamadı.');

        $id = (int) DB::table('terminal_protocol_samples')->insertGetId([
            'name' => (string) $request->input('ad', "RAW log #{$logId} ({$log->kind})"),
            'tx_hex' => $log->kind === 'TX' ? $log->payload_hex : null,
            'rx_hex' => $log->kind === 'RX' ? $log->payload_hex : null,
            'note' => "Oturum {$log->session_id} · {$log->occurred_at} · {$log->source} → {$log->target}",
            'source' => 'uygulama',
            'tcp_log_id' => $logId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->ok('Analize eklendi.', ['id' => $id]);
    }

    public function deleteSample(int $id): JsonResponse
    {
        if ($r = $this->needDev()) {
            return $r;
        }
        DB::table('terminal_protocol_samples')->where('id', $id)->delete();

        return $this->ok('Örnek silindi.');
    }

    public function compare(Request $request, PacketAnalyzer $analyzer): JsonResponse
    {
        if ($r = $this->needDev()) {
            return $r;
        }

        $data = $request->validate([
            'idler' => ['required', 'array', 'min:2', 'max:20'],
            'idler.*' => ['integer'],
            'taraf' => ['required', Rule::in(['tx', 'rx'])],
            'makine_id' => ['nullable', 'integer', 'between:0,65535'],
        ], ['idler.min' => 'Karşılaştırma için en az iki örnek seçin.']);

        $rows = DB::table('terminal_protocol_samples')->whereIn('id', $data['idler'])->orderBy('id')->get();
        $col = $data['taraf'] === 'tx' ? 'tx_hex' : 'rx_hex';
        $packets = $rows->map(fn ($r) => $r->{$col} ? (string) hex2bin($r->{$col}) : '')->all();

        return response()->json($analyzer->compare($packets, isset($data['makine_id']) ? (int) $data['makine_id'] : null) + [
            'ornekler' => $rows->map(fn ($r) => ['id' => $r->id, 'ad' => $r->name])->all(),
        ]);
    }

    /** Cihaz web paneli trafiği (tarayıcı › Save all as HAR). Parolalar/çerezler maskelenir. */
    public function importHar(Request $request, HarImporter $har): JsonResponse
    {
        if ($r = $this->needDev()) {
            return $r;
        }

        $request->validate(['dosya' => ['required', 'file', 'max:51200'], 'yalniz_host' => ['nullable', 'string', 'max:100']], ['dosya.required' => 'HAR dosyası seçin.']);
        $rows = $har->parse((string) file_get_contents($request->file('dosya')->getRealPath()), $request->input('yalniz_host') ?: null);
        $now = now();

        foreach ($rows as $row) {
            DB::table('terminal_protocol_samples')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
        }

        Audit::log('device.terminal_har_imported', count($rows).' HAR isteği protokol analizine eklendi (parolalar/çerezler maskeli).');

        return $this->ok(count($rows).' istek içe aktarıldı (parola/çerez alanları maskelendi).', ['adet' => count($rows)]);
    }

    private function needDev(): ?JsonResponse
    {
        return $this->state->developerMode() ? null : response()->json(['message' => self::DEV_MESSAGE, 'error_code' => 'terminal_dev_mode_required'], 403);
    }

    private function maskIp(string $text): string
    {
        return (string) preg_replace('/\b(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}\b/', '$1.$2.x.x', $text);
    }
}
