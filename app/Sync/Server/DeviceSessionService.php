<?php

namespace App\Sync\Server;

use App\Models\User;
use App\Sync\Models\SyncDevice;
use App\Sync\SyncFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Masaüstü (yerel kurulum) oturumları — sunucu tarafı.
 *
 * Cihaz her eşitleme turunda (en fazla dakikada bir) AÇIK yerel oturumlarının özetini gönderir:
 * yerel kullanıcı (uuid) + sha256(oturum kimliği) + son etkinlik. Ham oturum kimliği hiçbir zaman gelmez.
 * Sunucu tam listeyi tutar (raporda olmayan satırlar silinir) ve bekleyen kapatma isteklerini yanıtta döner;
 * cihaz yerel oturumu silip onaylar. Cihaz çevrimdışıysa istek bekler, ilk bağlantıda uygulanır.
 */
class DeviceSessionService
{
    /** Yerel kurulumun oturum ömrü (SESSION_LIFETIME=720 dk): bundan eski etkinlik kendiliğinden düşmüştür. */
    public const STALE_MINUTES = 720;

    public const MAX_SESSIONS = 200;

    private static ?bool $ready = null;

    /** Göç henüz çalışmadıysa (tablolar yok) liste boş döner; oturum sayfası bozulmaz. */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('sync_device_sessions') && Schema::hasTable('sync_session_revocations');
    }

    public static function flushReady(): void
    {
        self::$ready = null;
    }

    /**
     * @param  list<array{user: string, hash: string, last_activity?: int|string|null, user_agent?: ?string}>  $sessions
     * @param  list<int>  $acks  uygulanan kapatma istekleri
     * @return array{accepted: int, revocations: list<array{id: int, user: string, hash: string}>}
     */
    public function report(SyncDevice $device, array $sessions, array $acks = []): array
    {
        if (! self::ready()) {
            return ['accepted' => 0, 'revocations' => []];
        }
        $uuids = array_values(array_unique(array_map(fn ($s) => (string) $s['user'], $sessions)));
        // Yalnız cihaz şubesindeki personel hesapları (cihaz başka şubenin/portal hesabının oturumunu raporlayamaz)
        $users = $uuids ? DB::table('users')->whereIn('uuid', $uuids)
            ->whereIn('user_type', SyncFilters::STAFF_TYPES)
            ->where(fn ($q) => $q->where('branch_id', $device->branch_id)->orWhereNull('branch_id'))
            ->whereNull('deleted_at')
            ->pluck('id', 'uuid') : collect();

        $now = now();
        $rows = [];
        foreach ($sessions as $s) {
            $userId = $users[(string) $s['user']] ?? null;
            $hash = strtolower((string) $s['hash']);
            if (! $userId || ! preg_match('/^[0-9a-f]{64}$/', $hash)) {
                continue;
            }
            $at = $s['last_activity'] ?? null;
            $at = is_numeric($at) ? Carbon::createFromTimestamp((int) $at, config('app.timezone')) : ($at ? Carbon::parse((string) $at) : null);
            if ($at && $at->gt($now)) {
                $at = $now->copy();   // cihaz saati ileride olabilir
            }
            $rows[$hash] = [
                'device_id' => $device->id, 'user_id' => (int) $userId, 'session_hash' => $hash,
                'last_activity_at' => $at, 'user_agent' => isset($s['user_agent']) ? mb_substr((string) $s['user_agent'], 0, 255) : null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($device, $rows, $acks, $now) {
            $q = DB::table('sync_device_sessions')->where('device_id', $device->id);
            if ($rows) {
                $q->whereNotIn('session_hash', array_keys($rows));
            }
            $q->delete();
            if ($rows) {
                DB::table('sync_device_sessions')->upsert(array_values($rows), ['device_id', 'session_hash'], ['user_id', 'last_activity_at', 'user_agent', 'updated_at']);
            }
            if ($acks) {
                DB::table('sync_session_revocations')->where('device_id', $device->id)->where('status', 'pending')
                    ->whereIn('id', array_map('intval', $acks))->update(['status' => 'done', 'done_at' => $now, 'updated_at' => $now]);
            }
        });

        return ['accepted' => count($rows), 'revocations' => $this->pendingFor($device)];
    }

    /** @return list<array{id: int, user: string, hash: string}> */
    public function pendingFor(SyncDevice $device): array
    {
        return DB::table('sync_session_revocations as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.device_id', $device->id)->where('r.status', 'pending')
            ->orderBy('r.id')->limit(500)
            ->get(['r.id', 'u.uuid', 'r.session_hash'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'user' => (string) $r->uuid, 'hash' => (string) $r->session_hash])
            ->all();
    }

    /** Kullanıcının etkin cihazlardaki canlı yerel oturumları (+ bekleyen kapatma işareti). */
    public function rowsForUser(int $userId): Collection
    {
        if (! self::ready()) {
            return collect();
        }

        return $this->liveQuery()->where('s.user_id', $userId)->get();
    }

    public function findForUser(int $userId, string $hash): ?object
    {
        if (! self::ready()) {
            return null;
        }

        return $this->liveQuery()->where('s.user_id', $userId)->where('s.session_hash', strtolower($hash))->first();
    }

    /** Cihazın bu kullanıcı için bu oturumu raporlamış olması (cihaz vekilli uçların yetki kanıtı). */
    public function deviceHasSession(SyncDevice $device, int $userId, string $hash): bool
    {
        return DB::table('sync_device_sessions')->where('device_id', $device->id)->where('user_id', $userId)
            ->where('session_hash', strtolower($hash))->exists();
    }

    /**
     * Kapatma isteği (idempotent). Satır listede "kapatılıyor" görünür; cihaz onaylayınca raporla düşer.
     */
    public function requestRevoke(object $row, ?User $by): void
    {
        $exists = DB::table('sync_session_revocations')->where('device_id', $row->device_id)
            ->where('session_hash', $row->session_hash)->where('status', 'pending')->exists();
        if (! $exists) {
            DB::table('sync_session_revocations')->insert([
                'device_id' => $row->device_id, 'user_id' => $row->user_id, 'session_hash' => $row->session_hash,
                'status' => 'pending', 'requested_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Bağlı cihazlar listesi için: cihaz başına açık oturumlar.
     *
     * @param  list<int>  $deviceIds
     * @return array<int, list<array{user_id: int, name: ?string, last_active_at: ?string, closing: bool}>>
     */
    public function byDevice(array $deviceIds): array
    {
        if (! $deviceIds || ! self::ready()) {
            return [];
        }
        $out = [];
        foreach ($this->liveQuery()->whereIn('s.device_id', $deviceIds)->get() as $r) {
            $out[(int) $r->device_id][] = [
                'user_id' => (int) $r->user_id, 'name' => $r->user_name,
                'last_active_at' => $r->last_activity_at ? Carbon::parse($r->last_activity_at)->toIso8601String() : null,
                'closing' => (bool) $r->closing,
            ];
        }

        return $out;
    }

    private function liveQuery()
    {
        return DB::table('sync_device_sessions as s')
            ->join('sync_devices as d', 'd.id', '=', 's.device_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('d.status', 'active')
            ->where(fn ($q) => $q->whereNull('s.last_activity_at')->orWhere('s.last_activity_at', '>=', now()->subMinutes(self::STALE_MINUTES)))
            ->orderByDesc('s.last_activity_at')
            ->select([
                's.device_id', 's.user_id', 's.session_hash', 's.last_activity_at', 's.user_agent',
                'd.name as device_name', 'd.code as device_code', 'd.platform', 'd.app_version', 'd.last_ip', 'd.last_seen_at', 'd.branch_id',
                'u.name as user_name',
            ])
            ->selectSub(fn ($q) => $q->from('sync_session_revocations as r')->selectRaw('COUNT(*)')
                ->whereColumn('r.device_id', 's.device_id')->whereColumn('r.session_hash', 's.session_hash')->where('r.status', 'pending'), 'closing');
    }
}
