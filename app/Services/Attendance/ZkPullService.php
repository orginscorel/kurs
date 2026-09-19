<?php

namespace App\Services\Attendance;

use App\Exceptions\BusinessRuleException;
use App\Models\Device;
use App\Services\Devices\Zk\Exceptions\ZkException;
use App\Services\Devices\Zk\ZkAttendanceRecord;
use App\Services\Devices\Zk\ZkConnectionSettings;
use App\Services\Devices\Zk\ZkTerminal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * İÇE AKTARMA BORU HATTI — cihazdan çekilen ham okutmaları var olan yoklama yapısına verir.
 *
 * Yeni bir yoklama sistemi KURULMAZ; her kayıt PresenceService::ingest() üzerinden geçer:
 *   attendance_events (ham olay) → daily_presences (günlük özet) → veli bildirimi/canlı akış.
 *
 * Aynı kayıt iki kez işlenmez: idempotency_key = "zk:{cihaz}:{kullanıcı no}:{unix zaman}".
 * Bu anahtar attendance_events tablosunda TEKİL olduğundan, cihaz aynı kaydı tekrar verse de
 * (ör. --tam ile tüm bellek okunduğunda) ikinci kez yazılmaz. Böylece "device_id +
 * device_user_id + occurred_at" üçlüsü veritabanı düzeyinde benzersizdir.
 *
 * Eşleşmeyen kayıt KAYBOLMAZ: cihaz kullanıcı numarası hiçbir öğrenciye bağlı değilse olay
 * is_matched = false ile yazılır ve "Eşleşmemiş okutmalar" listesinde bekler.
 */
class ZkPullService
{
    public function __construct(private readonly PresenceService $presence) {}

    /**
     * @param  bool  $full  true: imleç yok sayılır, cihazdaki tüm kayıtlar okunur (yinelenenler atlanır)
     * @param  ZkTerminal|null  $terminal  testte sahte cihaz enjekte etmek için
     * @return array{cihaz:string, okunan:int, islenen:int, yinelenen:int, eslesmeyen:int, yoksayilan:int, imlec:?string}
     */
    public function pull(Device $device, bool $full = false, ?ZkTerminal $terminal = null): array
    {
        // ZK paketleri yalnız ZK sürücüsü seçilmiş cihaza gönderilir (Perkotek YT33/FK farklı protokoldür).
        if ($terminal === null && $device->protocol !== null && $device->protocol !== 'zk') {
            throw new BusinessRuleException(
                "\"{$device->name}\" cihazının sürücüsü ZKTeco değil; ZKTeco kayıt çekme bu cihaza uygulanmaz.",
                'terminal_driver_mismatch', ['device_id' => $device->id, 'protocol' => $device->protocol],
            );
        }

        $lock = Cache::lock('zk-pull:'.$device->id, 600);

        if (! $lock->get()) {
            throw new BusinessRuleException(
                "\"{$device->name}\" cihazından şu anda başka bir çekme işlemi sürüyor. Bitmesini bekleyin.",
                'zk_pull_locked', ['device_id' => $device->id], 409,
            );
        }

        try {
            return $this->run($device, $full, $terminal);
        } finally {
            $lock->release();
        }
    }

    private function run(Device $device, bool $full, ?ZkTerminal $terminal): array
    {
        $since = $this->since($device, $full);
        $owned = $terminal === null;

        try {
            $terminal ??= ZkTerminal::open(ZkConnectionSettings::fromDevice($device));
            $records = $terminal->attendance($since, (int) config('devices_zk.max_records', 20000));
            $info = $terminal->info();
        } catch (ZkException $e) {
            $this->markFailure($device, $e);

            throw $e;
        } finally {
            if ($owned && isset($terminal)) {
                $terminal->close();
            }
        }

        $stats = ['okunan' => count($records), 'islenen' => 0, 'yinelenen' => 0, 'eslesmeyen' => 0, 'yoksayilan' => 0];
        $cursor = $device->zk_cursor_at ? CarbonImmutable::parse($device->zk_cursor_at) : null;
        $cursorKey = $device->zk_cursor_key;

        usort($records, fn (ZkAttendanceRecord $a, ZkAttendanceRecord $b) => $a->timestamp <=> $b->timestamp);

        foreach ($records as $record) {
            $key = $this->idempotencyKey($device, $record);
            $result = $this->presence->ingest([
                'identifier' => $record->userId,
                'identifier_kind' => 'fingerprint',   // eşleme cihaz KULLANICI NUMARASI ile yapılır
                'event_type' => $record->direction((array) config('devices_zk.punch_directions', [])),
                'occurred_at' => $record->timestamp->format('Y-m-d H:i:s'),
                'idempotency_key' => $key,
                'source' => $record->source((array) config('devices_zk.verify_sources', [])),
            ], $device);

            match ($result['status']) {
                'accepted', 'staff' => $stats['islenen']++,
                'duplicate' => $stats['yinelenen']++,
                'unmatched' => $stats['eslesmeyen']++,
                default => $stats['yoksayilan']++,
            };

            if ($cursor === null || $record->timestamp->greaterThanOrEqualTo($cursor)) {
                $cursor = $record->timestamp;
                $cursorKey = $key;
            }
        }

        $device->forceFill([
            'zk_cursor_at' => $cursor,
            'zk_cursor_key' => $cursorKey,
            'zk_last_pull_at' => now(),
            'zk_last_status' => 'ok',
            'zk_last_error' => null,
            'zk_last_record_count' => $stats['okunan'],
            'last_seen_at' => now(),           // köprü canlı: otomatik "gelmedi" yazımı durmasın
            'serial_no' => $info->serialNumber ?: $device->serial_no,
            'firmware' => $info->firmware ?: $device->firmware,
        ])->save();

        return ['cihaz' => $device->name, 'imlec' => $cursor?->format('Y-m-d H:i:s')] + $stats;
    }

    /** Cihaz + kullanıcı + saniye → tekil anahtar (en çok 80 karakter; attendance_events.idempotency_key). */
    public function idempotencyKey(Device $device, ZkAttendanceRecord $record): string
    {
        $user = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $record->userId) ?: '0', 0, 24);

        return 'zk:'.$device->id.':'.$user.':'.$record->timestamp->getTimestamp();
    }

    /** Hangi andan sonraki kayıtlar okunacak? (imleç; ilk çekmede yapılandırılmış gün sayısı) */
    private function since(Device $device, bool $full): ?CarbonImmutable
    {
        if ($full) {
            return null;
        }

        if ($device->zk_cursor_at) {
            return CarbonImmutable::parse($device->zk_cursor_at);
        }

        $days = (int) config('devices_zk.first_pull_days', 7);

        return $days > 0 ? CarbonImmutable::now()->subDays($days)->startOfDay() : null;
    }

    private function markFailure(Device $device, ZkException $e): void
    {
        $device->forceFill([
            'zk_last_pull_at' => now(),
            'zk_last_status' => 'error',
            'zk_last_error' => mb_substr('['.$e->code().'] '.$e->getMessage(), 0, 300),
        ])->save();
    }
}
