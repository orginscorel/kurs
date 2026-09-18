<?php

namespace App\Sync\Local;

use App\Models\User;
use App\Services\Auth\SessionDirectory;
use App\Sync\SyncContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Yerel kurulumun (masaüstü) açık oturumları. Yerelde SESSION_DRIVER=file: her oturum
 * storage/framework/sessions/<oturum kimliği> dosyasıdır. Dosya adı (ham kimlik) bu sınıfın DIŞINA çıkmaz;
 * dışarıya yalnız sha256 özeti verilir. Son etkinlik = dosyanın değişiklik zamanı (her istekte yazılır).
 */
class LocalSessionStore
{
    public static function hashOf(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** Oturum dosyaları bu sürücüyle mi tutuluyor? (sunucu düğümünde database) */
    public function supported(): bool
    {
        return config('session.driver') === 'file';
    }

    /**
     * Oturum açılmış (kullanıcılı) ve süresi dolmamış yerel oturumlar.
     *
     * @return list<array{hash: string, user_id: int, last_activity: int}>
     */
    public function all(): array
    {
        if (! $this->supported()) {
            return [];
        }
        $dir = (string) config('session.files');
        $minTime = time() - ((int) config('session.lifetime', 120)) * 60;
        $key = Auth::guard('web')->getName();
        $out = [];
        foreach ((array) @scandir($dir) as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9]{20,128}$/', $name)) {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$name;
            $mtime = @filemtime($path);
            if (! $mtime || $mtime < $minTime) {
                continue;
            }
            $userId = $this->userIdOf((string) @file_get_contents($path), $key);
            if ($userId) {
                $out[] = ['hash' => self::hashOf($name), 'user_id' => $userId, 'last_activity' => $mtime];
            }
        }
        usort($out, fn ($a, $b) => $b['last_activity'] <=> $a['last_activity']);

        return $out;
    }

    /** @return list<array{hash: string, user_id: int, last_activity: int}> */
    public function forUser(int $userId): array
    {
        return array_values(array_filter($this->all(), fn ($s) => $s['user_id'] === $userId));
    }

    /**
     * Özeti verilen oturum(lar)ı siler. $userId verilirse yalnız o kullanıcının oturumu silinir.
     * Silinen kullanıcılar için "beni hatırla" jetonu yenilenir (çerez oturumu geri açmasın).
     *
     * @param  list<string>  $hashes
     * @return list<int> oturumu kapatılan kullanıcılar
     */
    public function deleteByHashes(array $hashes, ?int $userId = null, ?string $keepSessionId = null): array
    {
        if (! $this->supported() || ! $hashes) {
            return [];
        }
        $wanted = array_flip(array_map('strtolower', $hashes));
        $dir = (string) config('session.files');
        $key = Auth::guard('web')->getName();
        $users = [];
        foreach ((array) @scandir($dir) as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9]{20,128}$/', $name) || ! isset($wanted[self::hashOf($name)])) {
                continue;
            }
            if ($keepSessionId !== null && hash_equals($keepSessionId, $name)) {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$name;
            $owner = $this->userIdOf((string) @file_get_contents($path), $key);
            if ($userId !== null && $owner !== $userId) {
                continue;
            }
            @unlink($path);
            if ($owner) {
                $users[$owner] = true;
            }
        }
        foreach (array_keys($users) as $id) {
            $this->cycleRememberToken($id);
        }

        return array_keys($users);
    }

    /** Yerel users satırında hatırlatma jetonu (eşitlenmez: remember_token dışlanan sütun). */
    public function cycleRememberToken(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }
        $current = request()->hasSession() ? ['web' => request()->session()->getId()] : [];
        app(SyncContext::class)->applying(fn () => app(SessionDirectory::class)->cycleRememberToken($user, $current));
    }

    private function userIdOf(string $raw, string $key): ?int
    {
        if ($raw === '') {
            return null;
        }
        if (config('session.encrypt')) {
            try {
                $raw = (string) Crypt::decrypt($raw);
            } catch (\Throwable) {
                return null;
            }
        }
        $data = config('session.serialization', 'php') === 'json'
            ? json_decode($raw, true)
            : @unserialize($raw, ['allowed_classes' => false]);
        $id = is_array($data) ? ($data[$key] ?? null) : null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** Yerel kullanıcı kimliği → sunucu uuid'si */
    public static function uuidsOf(array $userIds): array
    {
        return $userIds ? DB::table('users')->whereIn('id', array_unique($userIds))->pluck('uuid', 'id')->all() : [];
    }
}
