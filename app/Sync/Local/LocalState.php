<?php

namespace App\Sync\Local;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Yerel düğüm durumu. İmleçler veritabanında (uygulanan değişikliklerle aynı transaction),
 * anlık görünüm (aşama, son hata) durum dosyasında: storage/app/private/sync/state.json.
 */
class LocalState
{
    public function get(string $key, ?string $default = null): ?string
    {
        $v = DB::table('sync_state')->where('key', $key)->value('value');

        return $v === null ? $default : (string) $v;
    }

    public function put(string $key, string|int|null $value): void
    {
        DB::table('sync_state')->updateOrInsert(['key' => $key], ['value' => $value === null ? null : (string) $value, 'updated_at' => now()]);
    }

    public function serverCursor(): int
    {
        return (int) $this->get('server_cursor', '0');
    }

    public function pushedUpTo(): int
    {
        return (int) $this->get('pushed_up_to', '0');
    }

    public function pendingCount(): int
    {
        return (int) DB::table('sync_changes')->where('id', '>', $this->pushedUpTo())->whereNull('status')->count();
    }

    public function rejectedCount(): int
    {
        return (int) DB::table('sync_changes')->where('status', 'rejected')->count();
    }

    /** @return array<string, mixed> */
    public function file(): array
    {
        try {
            $raw = Storage::disk('local')->get($this->path());

            return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function writeFile(array $patch): void
    {
        $data = array_merge($this->file(), $patch, ['updated_at' => now()->toIso8601String()]);
        Storage::disk('local')->put($this->path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function path(): string
    {
        return (string) config('sync.state_file', 'sync/state.json');
    }

    public function requestSync(): void
    {
        $this->put('sync_requested_at', now()->toIso8601String());
    }

    /**
     * Gösterge özeti.
     *
     * @return array{phase: string, paired: bool, last_success_at: ?string, last_error: ?string, pending: int, rejected: int, open_conflicts: ?int, server_url: ?string, device_code: ?string, next_attempt_at: ?string}
     */
    public function summary(): array
    {
        $file = $this->file();
        $paired = (bool) (config('sync.device_token') ?: $this->get('device_token_set'));
        $phase = $file['phase'] ?? 'idle';
        $last = $this->get('last_success_at');
        // Döngü 5 dk'dan uzun süredir başarılı değilse ve son deneme hata verdiyse çevrimdışı
        if ($phase === 'syncing' && isset($file['updated_at']) && strtotime($file['updated_at']) < time() - 300) {
            $phase = 'stale';
        }
        // Son deneme 5 dk'dan eskiyse "çevrimdışı/hata" bayattır (tur hiç çalışmıyor): "Eşitleme bekliyor" göster
        $lastAttempt = $file['last_attempt_at'] ?? ($file['updated_at'] ?? null);
        if (in_array($phase, ['offline', 'error'], true) && $lastAttempt && strtotime($lastAttempt) < now()->getTimestamp() - 300) {
            $phase = 'stale';
        }

        return [
            'phase' => $paired ? $phase : 'unpaired',
            'paired' => $paired,
            'last_success_at' => $last,
            'last_error' => $file['last_error'] ?? null,
            'last_error_detail' => $file['last_error_detail'] ?? null,
            'last_attempt_at' => $file['last_attempt_at'] ?? null,
            'pending' => $this->pendingCount(),
            'rejected' => $this->rejectedCount(),
            'open_conflicts' => isset($file['open_conflicts']) ? (int) $file['open_conflicts'] : null,
            'server_url' => config('sync.server_url'),
            'device_code' => $this->get('device_code'),
            'next_attempt_at' => $file['next_attempt_at'] ?? null,
            'files_pending' => (int) $this->get('files_pending', '0'),
        ];
    }
}
