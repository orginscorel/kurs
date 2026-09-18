<?php

namespace App\Sync\Local;

use App\Sync\Contracts\TerminalStatusProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Yerel kurulum → sunucu: BİYOMETRİK TERMİNAL DURUM RAPORU.
 *
 * Köprü (kurs:cihaz-cek) yalnız kurumdaki Mac'te çalışır; son çekme zamanı, sonucu, hatası ve okunan kayıt sayısı
 * düğüme özeldir ve satır olarak eşitlenmez (SyncRegistry › devices 'exclude'). Web ekranı yine de "Son durum:
 * Ofis Mac üzerinden, 2 dk önce · bağlı" gösterebilsin ve sunucudaki otomatik yoklama "cihaz verisi akmıyor"
 * diye yanlışlıkla durmasın diye her eşitleme turunda (en fazla dakikada bir) bu küçük rapor gönderilir.
 * İmleç, IP ve eski köprü jetonu GÖNDERİLMEZ. Ek teşhis alanları: App\Sync\Contracts\TerminalStatusProvider (details).
 */
class TerminalStatusReporter
{
    public const MIN_INTERVAL_SECONDS = 60;

    private const LAST_KEY = 'terminal_status_reported_at';

    public function __construct(
        private readonly SyncClient $client,
        private readonly LocalState $state,
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
            Log::info('Terminal durum raporu gönderilemedi', ['e' => $e->getMessage()]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, devices: int, accepted?: int}
     *
     * @throws SyncHttpException
     */
    public function report(): array
    {
        if (! $this->client->isPaired()) {
            return ['status' => 'unpaired', 'devices' => 0];
        }
        $payload = $this->payload();
        if ($payload === []) {
            $this->state->put(self::LAST_KEY, (string) time());

            return ['status' => 'nothing', 'devices' => 0];
        }
        $res = $this->client->terminalStatus($payload);
        $this->state->put(self::LAST_KEY, (string) time());

        return ['status' => 'ok', 'devices' => count($payload), 'accepted' => (int) ($res['accepted'] ?? 0)];
    }

    /**
     * Bu kurulumun gerçekten konuştuğu (en az bir kez çekme/nabız almış) terminaller.
     *
     * @return list<array<string, mixed>>
     */
    public function payload(): array
    {
        $iso = fn ($v) => $v === null || $v === '' ? null : CarbonImmutable::parse((string) $v)->toIso8601String();
        $out = [];
        $provider = app(TerminalStatusProvider::class);
        $rows = DB::table('devices')->whereNotNull('uuid')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('zk_last_pull_at')->orWhereNotNull('last_seen_at'))
            ->orderBy('id')->limit(100)->get();
        foreach ($rows as $r) {
            try {
                $details = $provider->details($r);
            } catch (\Throwable $e) {
                Log::info('Terminal ek durum bilgisi alınamadı', ['device' => $r->id, 'e' => $e->getMessage()]);
                $details = [];
            }
            $out[] = [
                'details' => $details === [] ? null : $details,
                'uuid' => (string) $r->uuid,
                'last_seen_at' => $iso($r->last_seen_at),
                'last_pull_at' => $iso($r->zk_last_pull_at),
                'status' => in_array($r->zk_last_status, ['ok', 'error'], true) ? $r->zk_last_status : null,
                'error' => $r->zk_last_error === null ? null : mb_substr((string) $r->zk_last_error, 0, 300),
                'record_count' => (int) $r->zk_last_record_count,
            ];
        }

        return $out;
    }
}
