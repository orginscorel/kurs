<?php

namespace App\Services\Devices\Network;

use App\Services\Devices\Drivers\Yt33\Debug;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HAM TCP OTURUMU (PULL, ör. YT33 TCP 5005) — tek bağlantı üzerinde sırayla: bağlan → (isteğe bağlı dinle) →
 * kullanıcının verdiği paketleri gönder, her birinin yanıtını bekle → kapat.
 *
 * Uygulama kendisi paket ÜRETMEZ; yalnız kullanıcının yazdığı baytlar gider. Her TX/RX baytı ve her durum geçişi
 * (Connecting / Connected / Waiting Response / Data Received / Timeout / Connection Closed / Connection Reset / Error)
 * `terminal_tcp_log` tablosuna (LOCAL) ms zaman damgasıyla ve `terminal` kanalına [YT33][…] biçiminde yazılır.
 * Soket açma TcpProbe'dan geçer (mevcut, çalışan bağlantı kodu yeniden yazılmadı).
 */
class TcpSession
{
    public const MAX_RX = 1048576;

    private const QUIET_MS = 300;   // yanıt geldikten sonra bu kadar sessizlik → yanıt bitti say

    private string $sid = '';

    private float $t0 = 0.0;

    private ?int $deviceId = null;

    private array $states = [];

    private Debug $debug;

    public function __construct(private readonly TcpProbe $probe) {}

    /**
     * @param  list<string>  $packets  gönderilecek HAM bayt dizileri (boş = yalnız dinle)
     */
    public function run(string $host, int $port, array $packets = [], float $responseWait = 3.0, float $initialListen = 0.0, ?int $deviceId = null, string $tag = 'TERMINAL', float $connectTimeout = 3.0): array
    {
        $this->sid = substr(bin2hex(random_bytes(8)), 0, 16);
        $this->t0 = microtime(true);
        $this->deviceId = $deviceId;
        $this->states = [];
        $this->debug = new Debug($tag);
        $responseWait = min(max($responseWait, 0.2), 30.0);
        $remote = "{$host}:{$port}";

        $this->state('Connecting', "→ {$remote}/tcp");
        $this->debug->tcp("Connecting {$remote}");

        [$stream, $socket] = $this->probe->open($host, $port, $connectTimeout);

        if ($stream === null) {
            $final = $socket->failure === SocketFailure::Timeout ? 'Timeout' : 'Error';
            $this->state($final, $socket->message);
            $final === 'Timeout' ? $this->debug->timeout("connect {$remote}") : $this->debug->error($socket->message);

            return $this->result('hata', $remote, $socket, [], 0, 0, '', 'baglanti_kurulamadi', $responseWait);
        }

        $local = "{$socket->localIp}:{$socket->localPort}";
        $this->state('Connected', "{$socket->durationMs} ms · yerel uç {$local}");
        $this->debug->tcp("Connected in {$socket->durationMs}ms", ['yerel' => $local]);
        stream_set_blocking($stream, false);

        $tx = 0;
        $rx = 0;
        $allRx = '';
        $results = [];
        $close = null;

        // Önce dinle (cihaz bağlantıda kendiliğinden bir şey gönderiyor mu?)
        if ($initialListen > 0) {
            $this->state('Waiting Response', 'yalnız dinleniyor ('.$initialListen.' sn)');
            [$data, $close] = $this->read($stream, $initialListen, false, $remote, $local);
            $rx += strlen($data);
            $allRx .= $data;
            if ($data === '' && $close === null) {
                $this->state('Timeout', 'Dinleme süresince veri gelmedi');
                $this->debug->timeout("{$initialListen}s içinde veri yok");
            }
        }

        foreach ($packets as $i => $packet) {
            if ($close !== null) {
                break;
            }

            $n = @fwrite($stream, $packet);
            if ($n === false || $n < strlen($packet)) {
                $close = 'reset';
                $this->state('Connection Reset', 'Gönderim sırasında bağlantı koptu');
                break;
            }

            $tx += $n;
            $this->log('TX', null, $local, $remote, $packet);
            $this->debug->tx($packet);
            $this->state('Waiting Response', 'paket '.($i + 1).' gönderildi ('.$n.' B)');

            $sentAt = microtime(true);
            [$data, $close, $firstAt] = $this->read($stream, $responseWait, true, $remote, $local);
            $rx += strlen($data);
            $allRx .= $data;

            if ($data === '' && $close === null) {
                $this->state('Timeout', "paket ".($i + 1).": {$responseWait} sn içinde yanıt yok");
                $this->debug->timeout("paket ".($i + 1)." yanıtsız ({$responseWait}s)");
            }

            $results[] = [
                'sira' => $i + 1,
                'tx_bayt' => $n,
                'tx_hex' => bin2hex($packet),
                'rx_bayt' => strlen($data),
                'rx_hex' => bin2hex($data),
                'rx_dokum' => RawTcpDiagnostic::hexDump($data),
                'rx_ascii' => RawTcpDiagnostic::ascii($data),
                'yanit_ms' => $firstAt !== null ? (int) round(($firstAt - $sentAt) * 1000) : null,
                'durum' => $data !== '' ? 'yanit' : ($close !== null ? 'kapandi' : 'zaman_asimi'),
            ];
        }

        @fclose($stream);
        $reason = match ($close) {
            'peer' => 'cihaz_kapatti',
            'reset' => 'baglanti_sifirlandi',
            default => 'biz_kapattik',
        };
        if ($close === null) {
            $this->state('Connection Closed', 'Oturum bitti; bağlantıyı köprü kapattı');
        }
        $this->debug->close($reason);

        return $this->result('ok', $remote, $socket, $results, $tx, $rx, $allRx, $reason, $responseWait);
    }

    /**
     * Veri oku. $quiet: ilk bayttan sonra QUIET_MS sessizlikte dur; değilse süre dolana/kapanana dek oku.
     *
     * @return array{0:string, 1:?string, 2:?float}  [veri, kapanış ('peer'|'reset'|null), ilk bayt anı]
     */
    private function read($stream, float $seconds, bool $quiet, string $remote, string $local): array
    {
        $deadline = microtime(true) + $seconds;
        $buf = '';
        $first = null;
        $lastData = null;

        while (strlen($buf) < self::MAX_RX) {
            $now = microtime(true);
            if ($now >= $deadline || ($quiet && $lastData !== null && ($now - $lastData) * 1000 >= self::QUIET_MS)) {
                break;
            }

            $wait = $quiet && $lastData !== null ? min($deadline - $now, self::QUIET_MS / 1000) : $deadline - $now;
            $r = [$stream];
            $w = $e = null;
            $ready = @stream_select($r, $w, $e, (int) $wait, (int) (($wait - floor($wait)) * 1_000_000));

            if ($ready === false) {
                $this->state('Error', 'stream_select hatası');

                return [$buf, 'reset', $first];
            }
            if ($ready === 0) {
                continue;
            }

            error_clear_last();
            $chunk = @fread($stream, 65536);

            if ($chunk === false || $chunk === '') {
                $err = error_get_last()['message'] ?? '';
                if (str_contains(strtolower($err), 'reset')) {
                    $this->state('Connection Reset', 'Cihaz bağlantıyı sıfırladı (RST)');

                    return [$buf, 'reset', $first];
                }
                if (feof($stream)) {
                    $this->state('Connection Closed', 'Cihaz bağlantıyı kapattı (FIN)');

                    return [$buf, 'peer', $first];
                }

                continue;
            }

            $first ??= microtime(true);
            $lastData = microtime(true);
            $buf .= $chunk;
            $this->log('RX', null, $remote, $local, $chunk);
            $this->debug->rx($chunk);
            $this->state('Data Received', strlen($chunk).' B');
        }

        return [$buf, null, $first];
    }

    private function state(string $state, string $note = ''): void
    {
        $t = (int) round((microtime(true) - $this->t0) * 1000);
        $this->states[] = ['t_ms' => $t, 'durum' => $state, 'not' => $note];
        $this->log('STATE', $state, null, null, '', $note);
    }

    private function log(string $kind, ?string $state, ?string $source, ?string $target, string $bytes, string $note = ''): void
    {
        try {
            DB::table('terminal_tcp_log')->insert([
                'session_id' => $this->sid,
                'device_id' => $this->deviceId,
                'occurred_at' => now()->format('Y-m-d\TH:i:s.vP'),
                't_ms' => (int) round((microtime(true) - $this->t0) * 1000),
                'kind' => $kind,
                'state' => $state,
                'source' => $source,
                'target' => $target,
                'length' => strlen($bytes),
                'payload_hex' => $bytes !== '' ? bin2hex($bytes) : null,
                'note' => $note !== '' ? mb_substr($note, 0, 300) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Tablo henüz yoksa (göç öncesi) oturum yine çalışır; günlük dosyası kaydı sürer.
        }
    }

    private function result(string $status, string $remote, TcpProbeResult $socket, array $packets, int $tx, int $rx, string $allRx, string $reason, float $wait): array
    {
        $last = end($this->states) ?: ['durum' => null];

        return [
            'oturum' => $this->sid,
            'durum' => $status,
            'hedef' => $remote,
            'soket' => $socket->toArray(),
            'durumlar' => $this->states,
            'son_durum' => $last['durum'],
            'tx_bayt' => $tx,
            'rx_bayt' => $rx,
            'paketler' => $packets,
            'alinan_hex' => RawTcpDiagnostic::hexDump($allRx),
            'alinan_ham_hex' => bin2hex($allRx),
            'alinan_ascii' => RawTcpDiagnostic::ascii($allRx),
            'kapanis' => $reason,
            'zaman_asimi_sn' => $wait,
            'sure_ms' => (int) round((microtime(true) - $this->t0) * 1000),
            'bitis' => now()->toIso8601String(),
        ];
    }
}
