<?php

namespace App\Sync\Local;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Yerel kurulum → sunucu: AÇIK OTURUM RAPORU ve uzaktan kapatma.
 *
 * Her eşitleme turunda (en fazla dakikada bir) yerel oturumların özeti gönderilir (kullanıcı uuid'si +
 * sha256(oturum kimliği) + son etkinlik; ham kimlik ASLA). Yanıttaki bekleyen kapatma istekleri uygulanır
 * (oturum dosyası silinir, hatırlatma jetonu yenilenir → pencere bir sonraki istekte giriş ekranına düşer),
 * hemen ardından ikinci bir raporla onaylanır. Onay gönderilemezse sync_state'te bekler, sonraki turda gider.
 */
class SessionReporter
{
    public const MIN_INTERVAL_SECONDS = 60;

    private const ACKS_KEY = 'session_report_acks';

    private const LAST_KEY = 'session_reported_at';

    public function __construct(
        private readonly SyncClient $client,
        private readonly LocalState $state,
        private readonly LocalSessionStore $store,
    ) {}

    /** Eşitleme turundan çağrılır: hata turu düşürmez. */
    public function runSafely(bool $force = false): array
    {
        try {
            if (! $force && ($last = $this->state->get(self::LAST_KEY)) && (time() - (int) $last) < self::MIN_INTERVAL_SECONDS) {
                return ['status' => 'skipped'];
            }

            return $this->report();
        } catch (\Throwable $e) {
            Log::info('Oturum raporu gönderilemedi', ['e' => $e->getMessage()]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, sessions: int, applied: int}
     *
     * @throws SyncHttpException
     */
    public function report(): array
    {
        if (! $this->client->isPaired() || ! $this->store->supported()) {
            return ['status' => 'unpaired', 'sessions' => 0, 'applied' => 0];
        }
        $acks = array_map('intval', (array) json_decode((string) $this->state->get(self::ACKS_KEY, '[]'), true));
        $res = $this->send($acks);
        $this->state->put(self::ACKS_KEY, null);

        $applied = [];
        foreach ((array) ($res['revocations'] ?? []) as $r) {
            $userId = (int) DB::table('users')->where('uuid', (string) ($r['user'] ?? ''))->value('id');
            if ($userId && is_string($r['hash'] ?? null)) {
                $this->store->deleteByHashes([$r['hash']], $userId);
            }
            // Oturum yoksa (süresi dolmuş/çıkış yapılmış) da onaylanır: istek tamamlanmıştır.
            $applied[] = (int) $r['id'];
        }
        if ($applied) {
            // Önce kalıcı yaz: onay gönderilemezse sonraki turda gider (istek tekrar gelse de silme idempotent).
            $this->state->put(self::ACKS_KEY, json_encode($applied));
            $this->send($applied);
            $this->state->put(self::ACKS_KEY, null);
        }
        $this->state->put(self::LAST_KEY, (string) time());
        if (! empty($res['device']['name'])) {
            $this->state->put('device_name', (string) $res['device']['name']);
        }

        return ['status' => 'ok', 'sessions' => (int) ($res['accepted'] ?? 0), 'applied' => count($applied)];
    }

    /** Sunucudaki tam liste (web + uygulama + mobil) — yalnız bu cihazda oturumu açık kullanıcının kendisi. */
    public function userSessions(string $userUuid, string $sessionHash): array
    {
        return $this->call(fn (PendingRequest $h) => $h->get('sync/user-sessions', ['user' => $userUuid, 'session' => $sessionHash]), 'Oturum listesi');
    }

    /** Sunucu üzerinden kapatma (başka cihaz / tarayıcı). $id null ve $all true → mevcut dışındaki tümü. */
    public function revokeRemote(string $userUuid, string $sessionHash, ?string $id, bool $all = false): array
    {
        return $this->call(fn (PendingRequest $h) => $h->post('sync/user-sessions/revoke', array_filter([
            'user' => $userUuid, 'session' => $sessionHash, 'id' => $id, 'all' => $all ?: null,
        ], fn ($v) => $v !== null)), 'Oturum kapatma');
    }

    private function send(array $acks): array
    {
        $sessions = $this->store->all();
        $uuids = LocalSessionStore::uuidsOf(array_column($sessions, 'user_id'));
        $agent = 'ErbaaKurs Masaüstü '.(string) config('app.version', '');
        $payload = [];
        foreach ($sessions as $s) {
            if (! empty($uuids[$s['user_id']])) {
                $payload[] = ['user' => (string) $uuids[$s['user_id']], 'hash' => $s['hash'], 'last_activity' => $s['last_activity'], 'user_agent' => trim($agent)];
            }
        }

        return $this->call(fn (PendingRequest $h) => $h->post('sync/sessions', ['sessions' => array_slice($payload, 0, 200), 'acks' => array_values($acks)]), 'Oturum raporu');
    }

    private function call(callable $fn, string $what): array
    {
        if (! $this->client->isPaired()) {
            throw new SyncHttpException('Bu kurulum sunucuyla eşleştirilmemiş.', 0, 'device_not_paired');
        }
        $base = (string) $this->client->serverUrl();
        Http::allowStrayRequests([$base.'/*']);
        $http = Http::baseUrl($base.'/api/v1/')->acceptJson()->withToken((string) $this->client->token())
            ->withHeaders(['X-App-Version' => (string) config('app.version', 'yerel-1')])
            ->timeout(12)->connectTimeout(6);
        try {
            $r = $fn($http);
        } catch (ConnectionException $e) {
            throw new SyncHttpException('Sunucuya ulaşılamıyor.', 0, 'offline', SyncClient::shortDetail($e->getMessage()));
        }
        if ($r->failed()) {
            $errors = $r->json('errors');
            throw new SyncHttpException(
                (string) ((is_array($errors) ? collect($errors)->flatten()->first() : null) ?: $r->json('message') ?: "$what başarısız (HTTP {$r->status()})"),
                $r->status(), (string) ($r->json('error_code') ?? ''));
        }

        return (array) $r->json();
    }
}
