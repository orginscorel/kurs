<?php

namespace App\Sync\Server;

use App\Sync\Models\SyncDevice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sunucu: masaüstü kurulumlarından gelen TERMİNAL DURUM RAPORU ve BİLDİRİM OKUNDU bilgisi.
 *
 * Terminal durumu (son çekme/sonuç/hata/kayıt sayısı, son görülme) `sync_terminal_reports` tablosunda tutulur
 * (sync_ öneki: SYSTEM, eşitlenmez; terminal başına, raporlayan kurulum başına tek satır). `devices` satırına YALNIZ
 * `last_seen_at` ileri taşınır (DB::table, updated_at'e dokunmadan → günlüğe girmez): sunucudaki otomatik yoklama
 * (DeviceDataMonitor) köprünün canlı olduğunu buradan görür. Eski rapor yenisini ezmez.
 *
 * Web ekranı için sözleşme: statusFor() → terminal id => {via, reported_at, last_seen_at, last_pull_at, status,
 * error, record_count, connected, details}. IP / iletişim şifresi / imleç bu yoldan asla dönmez. `details`: yerel
 * TerminalStatusProvider'ın verdiği ek teşhis alanları (driver, mode, tcp_status, push_listener, pending_queue …).
 */
class TerminalStatusService
{
    public const MAX_DEVICES = 100;

    /** Raporu bu kadar dakikadan eski olan terminal "bağlı" sayılmaz (köprü dakikada bir çeker, rapor dakikada bir gelir). */
    public const FRESH_MINUTES = 10;

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{accepted: int, unknown: int}
     */
    public function report(SyncDevice $device, array $rows): array
    {
        $accepted = 0;
        $unknown = 0;
        $now = now()->format('Y-m-d H:i:s');
        foreach (array_slice($rows, 0, self::MAX_DEVICES) as $r) {
            $terminal = DB::table('devices')->where('uuid', (string) $r['uuid'])->where('branch_id', $device->branch_id)->whereNull('deleted_at')
                ->first(['id', 'last_seen_at']);
            if (! $terminal) {
                $unknown++;

                continue;
            }
            $key = ['terminal_id' => $terminal->id, 'sync_device_id' => $device->id];
            $prev = DB::table('sync_terminal_reports')->where($key)->first(['last_pull_at']);
            $pulled = self::time($r['last_pull_at'] ?? null);
            $seen = self::time($r['last_seen_at'] ?? null);
            $values = ['reported_at' => $now, 'updated_at' => $now, 'last_seen_at' => $seen?->format('Y-m-d H:i:s')];
            if (($details = self::details($r['details'] ?? null)) !== null) {
                $values['details'] = $details;   // gönderilmediyse önceki teşhis bilgisi kalır
            }
            if (! $prev || ! $prev->last_pull_at || ($pulled && $pulled->greaterThanOrEqualTo(CarbonImmutable::parse($prev->last_pull_at)))) {
                $values += [
                    'last_pull_at' => $pulled?->format('Y-m-d H:i:s'),
                    'status' => in_array($r['status'] ?? null, ['ok', 'error'], true) ? $r['status'] : null,
                    'error' => isset($r['error']) ? mb_substr((string) $r['error'], 0, 300) : null,
                    'record_count' => max(0, (int) ($r['record_count'] ?? 0)),
                ];
            }
            if (! $prev) {
                $values['created_at'] = $now;
            }
            DB::table('sync_terminal_reports')->updateOrInsert($key, $values);
            if ($seen && (! $terminal->last_seen_at || $seen->greaterThan(CarbonImmutable::parse($terminal->last_seen_at)))) {
                DB::table('devices')->where('id', $terminal->id)->update(['last_seen_at' => $seen->format('Y-m-d H:i:s')]);
            }
            $accepted++;
        }

        return ['accepted' => $accepted, 'unknown' => $unknown];
    }

    /**
     * Web ekranı: terminallerin masaüstünden gelen son durumu (en yeni rapor). Raporu olmayan terminal listede yer almaz.
     *
     * @param iterable<int|string> $terminalIds
     * @return array<int, array{via: string, reported_at: ?string, last_seen_at: ?string, last_pull_at: ?string, status: ?string, error: ?string, record_count: int, connected: bool}>
     */
    public static function statusFor(iterable $terminalIds): array
    {
        $ids = [];
        foreach ($terminalIds as $id) {
            $ids[] = (int) $id;
        }
        if ($ids === [] || ! \Illuminate\Support\Facades\Schema::hasTable('sync_terminal_reports')) {
            return [];
        }
        $rows = DB::table('sync_terminal_reports as r')->leftJoin('sync_devices as d', 'd.id', '=', 'r.sync_device_id')
            ->whereIn('r.terminal_id', $ids)->orderBy('r.reported_at')
            ->get(['r.*', 'd.name as via']);
        $iso = fn ($v) => $v ? CarbonImmutable::parse($v)->toIso8601String() : null;
        $fresh = CarbonImmutable::now()->subMinutes(self::FRESH_MINUTES);
        $out = [];
        foreach ($rows as $r) {   // en yeni rapor en sonda: onu tutar
            $connected = $r->status === 'ok' && $r->last_pull_at && CarbonImmutable::parse($r->last_pull_at)->greaterThan($fresh)
                && $r->reported_at && CarbonImmutable::parse($r->reported_at)->greaterThan($fresh);
            $out[(int) $r->terminal_id] = [
                'via' => (string) ($r->via ?? 'Masaüstü uygulaması'),
                'reported_at' => $iso($r->reported_at),
                'last_seen_at' => $iso($r->last_seen_at),
                'last_pull_at' => $iso($r->last_pull_at),
                'status' => $r->status,
                'error' => $r->error,
                'record_count' => (int) $r->record_count,
                'connected' => (bool) $connected,
                'details' => $r->details ? (json_decode((string) $r->details, true) ?: null) : null,
            ];
        }

        return $out;
    }

    /**
     * Masaüstünde okunan bildirimler. Yalnız cihazın şubesindeki PERSONEL kullanıcılarının, sunucudan inmiş (uuid'li)
     * ve henüz okunmamış satırları işaretlenir; tanınmayan uuid sessizce geçilir (yerelde üretilmiş bildirim).
     *
     * @param list<array{uuid: string, read_at?: ?string}> $reads
     */
    public function notificationReads(SyncDevice $device, array $reads): int
    {
        $staff = DB::table('users')->whereIn('user_type', \App\Sync\SyncFilters::STAFF_TYPES)
            ->where(fn ($q) => $q->where('branch_id', $device->branch_id)->orWhereNull('branch_id'))->select('id');
        $now = CarbonImmutable::now();
        $marked = 0;
        foreach (array_chunk($reads, 200) as $part) {
            $byTime = [];
            foreach ($part as $r) {
                $at = self::time($r['read_at'] ?? null) ?? $now;
                $byTime[($at->greaterThan($now) ? $now : $at)->format('Y-m-d H:i:s')][] = (string) $r['uuid'];
            }
            foreach ($byTime as $at => $uuids) {
                $marked += DB::table('app_notifications')->whereIn('uuid', $uuids)->whereNull('read_at')->whereIn('user_id', $staff)
                    ->update(['read_at' => $at, 'updated_at' => $now->format('Y-m-d H:i:s')]);
            }
        }

        return $marked;
    }

    /** Ek teşhis alanları (TerminalStatusProvider): en çok 40 anahtar, 4 KB; skaler olmayan/iç içe derin değerler atılır. */
    private static function details(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }
        $clean = [];
        foreach (array_slice($value, 0, 40, true) as $k => $v) {
            if (! is_string($k) || ! preg_match('/^[a-z0-9_]{1,40}$/', $k)) {
                continue;
            }
            if (is_array($v)) {
                $v = array_filter(array_slice($v, 0, 10, true), fn ($x) => is_scalar($x) || $x === null);
            } elseif (! is_scalar($v) && $v !== null) {
                continue;
            }
            $clean[$k] = is_string($v) ? mb_substr($v, 0, 300) : $v;
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

        return $clean === [] || $json === false || strlen($json) > 4096 ? null : $json;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
