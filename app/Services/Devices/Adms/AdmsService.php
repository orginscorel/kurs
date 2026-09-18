<?php

namespace App\Services\Devices\Adms;

use App\Models\Device;
use App\Services\Attendance\PresenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * ADMS (iclock) PUSH — cihaz kendi kayıtlarını HTTP ile bize yollar.
 *
 * Konuşma şekli (ZKTeco push aygıt yazılımı):
 *   GET  /iclock/cdata?SN=…&options=all   → sunucu cihaza ayar metni döner (el sıkışma)
 *   POST /iclock/cdata?SN=…&table=ATTLOG  → cihaz satır satır okutma gönderir, "OK" bekler
 *   GET  /iclock/getrequest?SN=…          → cihaz "bana komut var mı?" diye sorar (nabız)
 *   POST /iclock/devicecmd?SN=…           → komut sonucu bildirimi
 *
 * ATTLOG satırı (TAB ayraçlı):  kullanıcı_no  zaman  durum(punch)  doğrulama  işkodu  …
 *
 * AYNI BORU HATTI: kayıtlar ZKTeco köprüsüyle BİREBİR aynı yoldan geçer
 * (PresenceService::ingest), aynı idempotency deseniyle: "adms:{cihaz}:{kullanıcı}:{unix}".
 * Böylece cihaz aynı satırı tekrar gönderse de ikinci kez yazılmaz ve iki ayrı yoklama
 * sistemi oluşmaz.
 */
class AdmsService
{
    public function __construct(private readonly PresenceService $presence) {}

    /** Seri numarasından kayıtlı cihaz. Yoksa null döner ve "tanıtıldı ama ekli değil" olarak not edilir. */
    public function device(string $serial): ?Device
    {
        $serial = $this->normalizeSerial($serial);

        if ($serial === '') {
            return null;
        }

        $device = Device::query()->withoutGlobalScope('branch')
            ->whereRaw('UPPER(serial_no) = ?', [$serial])
            ->where('protocol', 'adms')
            ->first();

        if (! $device) {
            $this->rememberUnknown($serial);
        }

        return $device;
    }

    /** Cihazın el sıkışmada beklediği ayar metni. Biçim sabittir; satır sonu \n olmalıdır. */
    public function handshake(Device $device): string
    {
        $lines = [
            'GET OPTION FROM: '.$device->serial_no,
            'Stamp='.($device->adms_stamp ?: '0'),
            'OpStamp=0',
            'ErrorDelay='.config('devices_adms.error_delay', 30),
            'Delay='.config('devices_adms.delay', 10),
            'TransTimes=00:00;14:00',
            'TransInterval='.config('devices_adms.trans_interval', 1),
            'TransFlag=1111000000',
            'TimeZone='.config('devices_adms.timezone_offset', 3),
            'Realtime='.config('devices_adms.realtime', 1),
            'Encrypt=0',
        ];

        $this->touch($device, 'ok', null);

        return implode("\n", $lines)."\n";
    }

    /**
     * ATTLOG gövdesini işler.
     *
     * @return array{okunan:int, islenen:int, yinelenen:int, eslesmeyen:int, yoksayilan:int, hatali:int}
     */
    public function ingestAttlog(Device $device, string $body): array
    {
        $stats = ['okunan' => 0, 'islenen' => 0, 'yinelenen' => 0, 'eslesmeyen' => 0, 'yoksayilan' => 0, 'hatali' => 0];
        $max = (int) config('devices_adms.max_rows', 2000);
        $latest = null;

        foreach ($this->lines($body, $max) as $line) {
            $record = $this->parseAttlogLine($line);

            if ($record === null) {
                $stats['hatali']++;

                continue;
            }

            $stats['okunan']++;

            $result = $this->presence->ingest([
                'identifier' => $record['user'],
                'identifier_kind' => 'fingerprint',   // eşleme cihaz KULLANICI NUMARASI ile (ZK ile aynı)
                'event_type' => $this->direction($record['punch']),
                'occurred_at' => $record['at']->format('Y-m-d H:i:s'),
                'idempotency_key' => $this->idempotencyKey($device, $record['user'], $record['at']),
                'source' => $this->source($record['verify']),
            ], $device);

            match ($result['status']) {
                'accepted' => $stats['islenen']++,
                'duplicate' => $stats['yinelenen']++,
                'unmatched' => $stats['eslesmeyen']++,
                default => $stats['yoksayilan']++,
            };

            if ($latest === null || $record['at']->greaterThan($latest)) {
                $latest = $record['at'];
            }
        }

        $device->forceFill([
            'zk_last_pull_at' => now(),
            'zk_last_status' => 'ok',
            'zk_last_error' => null,
            'zk_last_record_count' => $stats['okunan'],
            'last_seen_at' => now(),
        ]);

        if ($latest !== null) {
            $device->forceFill(['zk_cursor_at' => $latest]);
        }

        $device->save();

        return $stats;
    }

    /** Cihazın nabzı: "hâlâ buradayım". */
    public function touch(Device $device, string $status = 'ok', ?string $error = null): void
    {
        $device->forceFill([
            'last_seen_at' => now(),
            'zk_last_status' => $status,
            'zk_last_error' => $error === null ? null : mb_substr($error, 0, 300),
        ])->save();
    }

    public function rememberStamp(Device $device, ?string $stamp): void
    {
        if ($stamp !== null && $stamp !== '' && $stamp !== $device->adms_stamp) {
            $device->forceFill(['adms_stamp' => mb_substr($stamp, 0, 40)])->save();
        }
    }

    /** Kaydı olmayan ama kendini tanıtan cihazlar (ekranda "ekle" önerisi çıkar). */
    public function unknownDevices(): array
    {
        return array_values(Cache::get('adms:unknown', []));
    }

    public function forgetUnknown(string $serial): void
    {
        $list = Cache::get('adms:unknown', []);
        unset($list[$this->normalizeSerial($serial)]);
        Cache::put('adms:unknown', $list, now()->addMinutes((int) config('devices_adms.unknown_ttl_minutes', 120)));
    }

    public function idempotencyKey(Device $device, string $user, CarbonImmutable $at): string
    {
        $clean = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $user) ?: '0', 0, 24);

        return 'adms:'.$device->id.':'.$clean.':'.$at->getTimestamp();
    }

    /**
     * Tek ATTLOG satırı → kayıt. Ayraç TAB'dır; bazı aygıt yazılımları boşluk kullanır.
     *
     * @return array{user:string, at:CarbonImmutable, punch:int, verify:int}|null
     */
    public function parseAttlogLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $parts = preg_split('/\t+/', $line) ?: [];

        if (count($parts) < 2) {
            $parts = preg_split('/\s{1,}/', $line) ?: [];
            // "1001 2026-09-18 08:12:03 0 1" → tarih ve saat iki parçaya bölünmüştür, birleştir
            if (count($parts) >= 3 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[1]) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $parts[2] ?? '')) {
                $parts = array_merge([$parts[0], $parts[1].' '.$parts[2]], array_slice($parts, 3));
            }
        }

        $user = trim((string) ($parts[0] ?? ''));
        $time = trim((string) ($parts[1] ?? ''));

        if ($user === '' || $time === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($time, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return [
            'user' => mb_substr($user, 0, 60),
            'at' => $at,
            'punch' => (int) ($parts[2] ?? 0),
            'verify' => (int) ($parts[3] ?? 1),
        ];
    }

    /** @return list<string> */
    private function lines(string $body, int $max): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        return array_slice(array_filter($lines, fn ($l) => trim($l) !== ''), 0, max(1, $max));
    }

    private function direction(int $punch): string
    {
        $map = (array) config('devices_zk.punch_directions', []);

        return $map[$punch] ?? 'AUTO';
    }

    private function source(int $verify): string
    {
        $map = (array) config('devices_zk.verify_sources', []);

        return $map[$verify] ?? 'fingerprint';
    }

    private function normalizeSerial(string $serial): string
    {
        return mb_strtoupper(trim(preg_replace('/[^A-Za-z0-9_-]/', '', $serial) ?: ''));
    }

    private function rememberUnknown(string $serial): void
    {
        $ttl = (int) config('devices_adms.unknown_ttl_minutes', 120);
        $list = Cache::get('adms:unknown', []);
        $list[$serial] = ['seri_no' => $serial, 'ilk_gorulme' => $list[$serial]['ilk_gorulme'] ?? now()->toDateTimeString(), 'son_gorulme' => now()->toDateTimeString()];

        Cache::put('adms:unknown', $list, now()->addMinutes(max(5, $ttl)));
    }
}
