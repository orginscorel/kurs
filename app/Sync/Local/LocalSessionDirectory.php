<?php

namespace App\Sync\Local;

use App\Exceptions\BusinessRuleException;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Auth\SessionDirectory;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hesabım › Açık oturumlar — YEREL KURULUM (Mac uygulaması) tarafı.
 *
 * Çevrimiçiyse: önce yerel oturumlar raporlanır (bekleyen kapatmalar uygulanır), sonra sunucudan kullanıcının
 * TAM listesi (web + tüm Mac'ler + mobil) çekilir ve yerel listeyle birleştirilir.
 * Çevrimdışıysa: yalnız bu Mac'teki oturumlar + "Sunucuya bağlanınca tüm cihazlar görünür" notu.
 *
 * Kapatma: bu Mac'teki oturum HER ZAMAN (çevrimdışı da) yerelde hemen kapatılır. Başka cihaz/tarayıcı
 * oturumu YALNIZ çevrimiçiyken sunucu üzerinden kapatılır (kuyruğa alınmaz: eski/yanlış listeye göre
 * gecikmeli kapatma yapılmasın, kullanıcı sonucu hemen görsün).
 */
class LocalSessionDirectory
{
    public function __construct(
        private readonly LocalSessionStore $store,
        private readonly SessionReporter $reporter,
        private readonly SyncClient $client,
        private readonly LocalState $state,
    ) {}

    /** @return array{data: list<array<string, mixed>>, recent_logins: mixed, node: string, scope: string, notice: ?string} */
    public function list(User $user, Request $request): array
    {
        $current = $this->currentHash($request);
        $local = collect($this->localRows($user->id, $current));
        $logins = LoginEvent::query()->where('user_id', $user->id)->latest('created_at')->limit(15)->get()
            ->map(fn ($l) => $l->toArray() + ['source' => 'local']);

        $scope = 'local';
        $notice = 'Sunucuya bağlanınca tüm cihazlardaki (web ve diğer Mac\'ler) oturumlarınız da burada görünür.';
        $rows = $local;
        if ($this->client->isPaired() && $current) {
            try {
                $this->reporter->report();
                $remote = $this->reporter->userSessions($this->uuid($user), $current);
                $remoteRows = collect((array) ($remote['data'] ?? []));
                $ids = $remoteRows->pluck('id')->all();
                // Sunucu listesi esas; henüz raporlanmamış yerel oturum (yarış) varsa eklenir
                $rows = $remoteRows->concat($local->reject(fn ($r) => in_array($r['id'], $ids, true)));
                $logins = $logins->concat(collect((array) ($remote['recent_logins'] ?? []))->map(fn ($l) => $l + ['source' => 'server']))
                    ->sortByDesc('created_at')->take(15)->values();
                $scope = 'all';
                $notice = null;
            } catch (SyncHttpException $e) {
                $notice = $e->isOffline()
                    ? 'Şu an çevrimdışısınız; yalnız bu Mac\'teki oturumlar görünüyor. Sunucuya bağlanınca tüm cihazlar görünür.'
                    : 'Sunucudaki oturum listesi alınamadı ('.$e->getMessage().'). Yalnız bu Mac\'teki oturumlar görünüyor.';
            }
        } elseif (! $this->client->isPaired()) {
            $notice = 'Bu kurulum sunucuyla eşleştirilmemiş; yalnız bu Mac\'teki oturumlar görünüyor.';
        }

        return [
            'data' => SessionDirectory::sort($rows)->values()->all(),
            'recent_logins' => $logins->values(),
            'node' => 'local',
            'scope' => $scope,
            'notice' => $notice,
        ];
    }

    public function revoke(User $user, string $id, Request $request): string
    {
        $current = $this->currentHash($request);
        if (str_starts_with($id, 'app:') && $this->isLocal($user->id, substr($id, 4))) {
            $hash = strtolower(substr($id, 4));
            if ($current && hash_equals($hash, $current)) {
                throw new BusinessRuleException('Kullandığınız oturumu buradan kapatamazsınız; çıkış yapın.', 'current_session', [], 422);
            }
            $this->store->deleteByHashes([$hash], $user->id, $request->hasSession() ? $request->session()->getId() : null);
            Audit::log('auth.session_revoked', 'bu cihazdaki (Mac uygulaması) diğer oturumunu kapattı.', $user);
            $this->reporter->runSafely(true);

            return 'Oturum kapatıldı.';
        }

        $this->requireOnline();
        try {
            $this->reporter->report();
            $res = $this->reporter->revokeRemote($this->uuid($user), (string) $current, $id);
        } catch (SyncHttpException $e) {
            throw $this->translate($e);
        }

        return (string) ($res['message'] ?? 'Oturum kapatıldı.');
    }

    /** @return array{message: string, local: int, remote: bool} */
    public function revokeOthers(User $user, Request $request): array
    {
        $current = $this->currentHash($request);
        $hashes = array_values(array_filter(array_column($this->store->forUser($user->id), 'hash'), fn ($h) => $h !== $current));
        if ($hashes) {
            $this->store->deleteByHashes($hashes, $user->id, $request->hasSession() ? $request->session()->getId() : null);
            Audit::log('auth.sessions_revoked_all', sprintf('bu cihazdaki diğer %d oturumunu kapattı.', count($hashes)), $user);
        }

        if (! $this->client->isPaired() || ! $current) {
            return ['message' => $hashes ? 'Bu Mac\'teki diğer oturumlar kapatıldı.' : 'Kapatılacak başka oturum yok.', 'local' => count($hashes), 'remote' => false];
        }
        try {
            $this->reporter->report();
            $res = $this->reporter->revokeRemote($this->uuid($user), $current, null, true);

            return ['message' => (string) ($res['message'] ?? 'Diğer oturumlar kapatıldı.'), 'local' => count($hashes), 'remote' => true];
        } catch (SyncHttpException $e) {
            if ($e->isOffline()) {
                return [
                    'message' => ($hashes ? 'Bu Mac\'teki diğer oturumlar kapatıldı. ' : '').'Çevrimdışısınız: web ve diğer cihazlardaki oturumlar kapatılamadı; bağlantı gelince yeniden deneyin.',
                    'local' => count($hashes), 'remote' => false, 'warning' => true,
                ];
            }
            throw $this->translate($e);
        }
    }

    /**
     * Yönetici görünümü (yerel kurulumda): yalnız bu Mac'teki oturumlar.
     *
     * @return list<array<string, mixed>>
     */
    public function localRows(int $userId, ?string $currentHash = null): array
    {
        $name = $this->state->get('device_name') ?: 'Bu Mac';

        return array_map(fn ($s) => [
            'id' => 'app:'.$s['hash'],
            'kind' => 'app',
            'kind_label' => 'Mac uygulaması',
            'device' => $name,
            'device_code' => $this->state->get('device_code'),
            'detail' => 'Bu cihaz',
            'ip_address' => null,
            'last_active_at' => Carbon::createFromTimestamp($s['last_activity'])->toIso8601String(),
            'is_current' => $currentHash !== null && hash_equals($s['hash'], $currentHash),
            'status' => 'active',
            'local' => true,
        ], $this->store->forUser($userId));
    }

    public function isLocal(int $userId, string $hash): bool
    {
        return in_array(strtolower($hash), array_column($this->store->forUser($userId), 'hash'), true);
    }

    private function currentHash(Request $request): ?string
    {
        return $request->hasSession() ? LocalSessionStore::hashOf($request->session()->getId()) : null;
    }

    private function uuid(User $user): string
    {
        return (string) DB::table('users')->where('id', $user->id)->value('uuid');
    }

    private function requireOnline(): void
    {
        if (! $this->client->isPaired()) {
            throw new BusinessRuleException('Bu kurulum sunucuyla eşleştirilmemiş; yalnız bu Mac\'teki oturumlar kapatılabilir.', 'device_not_paired', [], 409);
        }
    }

    private function translate(SyncHttpException $e): BusinessRuleException
    {
        if ($e->isOffline()) {
            return new BusinessRuleException('İnternet bağlantısı yok. Web ve diğer cihazlardaki oturumlar yalnız çevrimiçiyken kapatılabilir (bu Mac\'teki oturumlar çevrimdışı da kapatılır). Bağlantı gelince yeniden deneyin.', 'offline_not_supported', [], 409);
        }

        return new BusinessRuleException($e->getMessage(), $e->errorCode ?: 'sync_error', [], $e->status >= 400 && $e->status < 500 ? $e->status : 409);
    }
}
