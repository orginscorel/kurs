<?php

namespace App\Services\Devices\Terminal;

/**
 * PUSH DİNLEYİCİSİ — cihazın kendisinin bağlanıp veri gönderdiği TCP sunucusu (yerel düğüm, varsayılan 0.0.0.0:7005).
 *
 * İki kip:
 *   • Yalnız dinle: gelen baytlar ham saklanır. İstek HTTP ise cihaza BOŞ `HTTP/1.1 200 OK` (Content-Length: 0)
 *     döner — yanıt içeriği uydurulmaz. Ham TCP'ye hiçbir şey gönderilmez.
 *   • Aktarma (şeffaf köprü): her cihaz bağlantısı için yukarı akış sunucusuna (ör. mevcut PDKS programı,
 *     192.168.68.5:7005) bağlanılır, baytlar iki yönde değiştirilmeden aktarılır ve HER YÖN AYRI kaydedilir.
 *     Cihaza kendi yanıtımız gönderilmez; yanıt yukarı akıştan gelir. Yukarı akışa bağlanılamazsa bağlantı
 *     kaydedilir ve yalnız-dinle davranışına düşülür (HTTP → boş 200, ham TCP → hiçbir şey).
 *
 * Tek süreç, tek iş parçacığı: stream_select ile eşzamanlı çok bağlantı; hiçbir çağrı bloklamaz
 * (yukarı akış bağlantısı da eşzamansız açılır) → aktarma gecikmesi en aza iner.
 * Kayıt birimi "parça": bir yönde art arda gelen baytlar; 0,4 sn sessizlik, 1 MB sınırı ya da kapanışla biter.
 */
class PushListener
{
    public const DEFAULT_PORT = 7005;

    public const MAX_CHUNK = 1048576;

    public const MAX_CONNECTIONS = 64;

    private const FLUSH_IDLE = 0.4;          // bir yönde bu kadar sessizlik → parça kaydedilir

    private const LISTEN_IDLE_CLOSE = 5.0;   // yalnız-dinle kipinde ham TCP bağlantısı bu kadar sessizse kapanır

    private const RELAY_IDLE_CLOSE = 90.0;   // aktarma kipinde iki yön de bu kadar sessizse kapanır

    private const MAX_LIFETIME = 600.0;      // tek bağlantının en uzun ömrü

    private const UPSTREAM_TIMEOUT = 3.0;

    private const EMPTY_200 = "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    /** @var array<string, array<string, mixed>> */
    private array $conns = [];

    private ?string $upstreamHost = null;

    private ?int $upstreamPort = null;

    public int $packets = 0;

    public int $connections = 0;

    /** Cihazlardan alınan toplam bayt. */
    public int $rxBytes = 0;

    /** Cihazlara gönderilen toplam bayt (boş 200 yanıtları + aktarmada yukarı akıştan gelenler). */
    public int $txBytes = 0;

    public ?string $lastDataAt = null;

    public ?string $lastIp = null;

    public ?string $lastAt = null;

    public ?string $lastRelayError = null;

    public function __construct(private readonly TerminalPacketStore $store) {}

    public function open(int $port, string $host = '0.0.0.0'): void
    {
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create(['socket' => ['so_reuseaddr' => true, 'backlog' => 64]]);
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);

        if ($server === false) {
            [$message, $hint] = self::bindError($port, (int) $errno, (string) $errstr);
            throw new PushListenerException($message, $hint, (int) $errno);
        }

        stream_set_blocking($server, false);
        $this->server = $server;
        $name = (string) stream_socket_get_name($server, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
    }

    public function port(): int
    {
        return $this->port;
    }

    public function isOpen(): bool
    {
        return $this->server !== null;
    }

    public function setUpstream(?string $host, ?int $port): void
    {
        $this->upstreamHost = $host ?: null;
        $this->upstreamPort = $host ? $port : null;
    }

    public function upstreamLabel(): ?string
    {
        return $this->upstreamHost ? "{$this->upstreamHost}:{$this->upstreamPort}" : null;
    }

    public function activeConnections(): int
    {
        return count($this->conns);
    }

    /** Tek döngü adımı: en çok $timeout saniye bekler. */
    public function tick(float $timeout = 0.25): void
    {
        if ($this->server === null) {
            return;
        }

        $read = [$this->server];
        $write = [];

        foreach ($this->conns as $c) {
            if (! $c['client_eof']) {
                $read[] = $c['client'];
            }
            if ($c['toClient'] !== '' && ! $c['client_eof_write']) {
                $write[] = $c['client'];
            }
            if ($c['up'] !== null) {
                if ($c['up_state'] === 'connecting' || ($c['up_state'] === 'connected' && $c['toUp'] !== '')) {
                    $write[] = $c['up'];
                }
                if ($c['up_state'] === 'connected' && ! $c['up_eof']) {
                    $read[] = $c['up'];
                }
            }
        }

        $except = null;
        $timeout = max(0.0, $timeout);
        $ready = @stream_select($read, $write, $except, (int) $timeout, (int) (($timeout - floor($timeout)) * 1_000_000));

        if ($ready === false) {
            usleep(20000);
        }

        $now = microtime(true);

        foreach ($read as $stream) {
            if ($stream === $this->server) {
                $this->accept($now);

                continue;
            }
            $this->onReadable($stream, $now);
        }

        foreach ($write as $stream) {
            $this->onWritable($stream, $now);
        }

        foreach (array_keys($this->conns) as $id) {
            $this->housekeep($id, microtime(true));
        }
    }

    /** Tüm bağlantıları (kayıtlarını yazarak) kapatır ve dinlemeyi bırakır. */
    public function close(): void
    {
        foreach (array_keys($this->conns) as $id) {
            $this->finish($id, 'dinleyici_kapandi');
        }

        if ($this->server) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    // ------------------------------------------------------------------ olaylar

    private function accept(float $now): void
    {
        $client = @stream_socket_accept($this->server, 0, $peer);

        if ($client === false) {
            return;
        }

        if (count($this->conns) >= self::MAX_CONNECTIONS) {
            @fclose($client);

            return;
        }

        stream_set_blocking($client, false);
        [$ip, $port] = \App\Services\Devices\Network\TcpProbe::split((string) $peer);
        $id = substr(bin2hex(random_bytes(8)), 0, 16);

        $conn = [
            'id' => $id, 'client' => $client, 'ip' => (string) $ip, 'port' => $port,
            'up' => null, 'up_state' => 'none', 'up_status' => null, 'up_deadline' => 0.0,
            'toUp' => '', 'toClient' => '',
            'buf' => ['d' => '', 'u' => ''], 'buf_at' => ['d' => 0.0, 'u' => 0.0], 'trunc' => ['d' => false, 'u' => false],
            'client_eof' => false, 'client_eof_write' => false, 'up_eof' => false, 'up_shut' => false, 'client_shut' => false,
            'http_replied' => false, 'close_after_write' => false, 'req' => '', 'req_done' => false,
            'started' => $now, 'last' => $now, 'relay' => $this->upstreamHost !== null,
        ];

        if ($this->upstreamHost !== null) {
            $errno = 0;
            $errstr = '';
            $up = @stream_socket_client("tcp://{$this->upstreamHost}:{$this->upstreamPort}", $errno, $errstr, self::UPSTREAM_TIMEOUT, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

            if ($up === false) {
                $conn['up_state'] = 'failed';
                $conn['up_status'] = \App\Services\Devices\Network\SocketFailure::classify((int) $errno, (string) $errstr)->value;
                $this->noteRelayError($conn['up_status']);
            } else {
                stream_set_blocking($up, false);
                $conn['up'] = $up;
                $conn['up_state'] = 'connecting';
                $conn['up_deadline'] = $now + self::UPSTREAM_TIMEOUT;
            }
        }

        $this->conns[$id] = $conn;
        $this->connections++;
        $this->lastIp = $conn['ip'];
        $this->lastAt = now()->toIso8601String();
    }

    private function onReadable($stream, float $now): void
    {
        foreach ($this->conns as $id => &$c) {
            if ($stream === $c['client']) {
                $data = @fread($stream, 65536);
                if ($data === '' || $data === false) {
                    if (feof($stream)) {
                        $c['client_eof'] = true;
                    }
                } else {
                    $this->rxBytes += strlen($data);
                    $this->lastDataAt = now()->toIso8601String();
                    $this->append($c, 'd', $data, $now);
                    if (! $c['req_done'] && strlen($c['req']) < self::MAX_CHUNK) {
                        $c['req'] .= $data;   // tek HTTP isteği (cihaz bağlantı başına bir istek gönderir)
                    }
                    if ($c['up_state'] === 'connecting' || $c['up_state'] === 'connected') {
                        $c['toUp'] .= $data;
                    }
                }

                return;
            }

            if ($stream === $c['up']) {
                $data = @fread($stream, 65536);
                if ($data === '' || $data === false) {
                    if (feof($stream)) {
                        $c['up_eof'] = true;
                    }
                } else {
                    $this->append($c, 'u', $data, $now);
                    $c['toClient'] .= $data;
                }

                return;
            }
        }
        unset($c);
    }

    private function onWritable($stream, float $now): void
    {
        foreach ($this->conns as $id => &$c) {
            if ($stream === $c['up']) {
                if ($c['up_state'] === 'connecting') {
                    // Eşzamansız bağlantı: yazılabilir + karşı uç adı varsa bağlandı, yoksa reddedildi
                    if (@stream_socket_get_name($stream, true) !== false) {
                        $c['up_state'] = 'connected';
                        $c['up_status'] = 'connected';
                    } else {
                        $this->relayFailed($c, 'refused');

                        return;
                    }
                }
                if ($c['toUp'] !== '') {
                    $n = @fwrite($stream, $c['toUp']);
                    if ($n === false) {
                        $c['up_eof'] = true;
                        $c['toUp'] = '';
                    } elseif ($n > 0) {
                        $c['toUp'] = (string) substr($c['toUp'], $n);
                    }
                }

                return;
            }

            if ($stream === $c['client']) {
                $n = @fwrite($stream, $c['toClient']);
                if ($n === false) {
                    $c['client_eof'] = true;
                    $c['client_eof_write'] = true;
                    $c['toClient'] = '';
                } elseif ($n > 0) {
                    $this->txBytes += $n;
                    $c['toClient'] = (string) substr($c['toClient'], $n);
                }

                return;
            }
        }
        unset($c);
    }

    private function housekeep(string $id, float $now): void
    {
        $c = &$this->conns[$id];

        if ($c['up_state'] === 'connecting' && $now > $c['up_deadline']) {
            $this->relayFailed($c, 'timeout');
        }

        $relaying = $c['up_state'] === 'connected' || $c['up_state'] === 'connecting';

        // HTTP isteği tamamlandı: önce ham kayıt (gizlenmiş), sonra işleme; kendimiz yanıtlıyorsak onay/boş 200.
        // Aktarmada yanıt yukarı akıştan gelir, biz yalnız kaydeder ve işleriz.
        if (! $c['req_done'] && $c['req'] !== '' && self::httpComplete($c['req'])) {
            $c['req_done'] = true;
            $packetId = $this->flush($c, 'd', 'http_istek');
            $reply = $this->onHttpRequest($c['req'], $c['ip'], $packetId);
            $c['req'] = '';

            if (! $relaying) {
                $c['toClient'] .= $reply ?? self::EMPTY_200;
                $c['http_replied'] = true;
                $c['close_after_write'] = true;
            }
        }

        // Sessizleşen yönün parçasını kaydet
        foreach (['d', 'u'] as $dir) {
            if ($c['buf'][$dir] !== '' && $now - $c['buf_at'][$dir] >= self::FLUSH_IDLE) {
                $this->flush($c, $dir, 'parca');
            }
        }

        if (! $relaying) {
            if ($c['close_after_write'] && $c['toClient'] === '') {
                $this->finish($id, 'http_200_gonderildi');

                return;
            }

            if ($c['client_eof'] || $now - $c['last'] > self::LISTEN_IDLE_CLOSE) {
                $this->finish($id, $c['client_eof'] ? 'cihaz_kapatti' : 'sessizlik');

                return;
            }
        } else {
            // Aktarma: bir uç kapatınca bekleyeni ilet, diğer ucun yazma yönünü kapat (yarı kapanış)
            if ($c['client_eof'] && $c['toUp'] === '' && $c['up_state'] === 'connected' && ! $c['up_shut']) {
                @stream_socket_shutdown($c['up'], STREAM_SHUT_WR);
                $c['up_shut'] = true;
            }
            if ($c['up_eof'] && $c['toClient'] === '' && ! $c['client_shut'] && ! $c['client_eof']) {
                @stream_socket_shutdown($c['client'], STREAM_SHUT_WR);
                $c['client_shut'] = true;
            }
            if (($c['client_eof'] && $c['up_eof']) || ($c['client_eof'] && $c['up_shut'] && $now - $c['last'] > 5.0) || ($c['up_eof'] && $c['client_shut'] && $now - $c['last'] > 5.0)) {
                $this->finish($id, 'iki_uc_kapatti');

                return;
            }
            if ($now - $c['last'] > self::RELAY_IDLE_CLOSE) {
                $this->finish($id, 'sessizlik');

                return;
            }
        }

        if ($now - $c['started'] > self::MAX_LIFETIME) {
            $this->finish($id, 'azami_sure');
        }
    }

    // ------------------------------------------------------------------ yardımcılar

    private function append(array &$c, string $dir, string $data, float $now): void
    {
        $c['last'] = $now;
        $c['buf_at'][$dir] = $now;
        $room = self::MAX_CHUNK - strlen($c['buf'][$dir]);

        if ($room <= 0 || strlen($data) > $room) {
            $c['buf'][$dir] .= substr($data, 0, max(0, $room));
            $c['trunc'][$dir] = true;
            $this->flush($c, $dir, 'bayt_siniri');
            $rest = (string) substr($data, max(0, $room));
            $c['buf'][$dir] = substr($rest, 0, self::MAX_CHUNK);
            $c['buf_at'][$dir] = $now;

            return;
        }

        $c['buf'][$dir] .= $data;
    }

    /**
     * Tamamlanmış HTTP isteği için sürücü kancası: işler ve cihaza gidecek yanıtı döner (null → boş 200).
     * Aktarma kipinde de çağrılır; o zaman dönen yanıt kullanılmaz (yanıtı yukarı akış verir).
     */
    protected function onHttpRequest(string $request, string $remoteIp, ?int $packetId): ?string
    {
        return null;
    }

    /**
     * Kayda yazılmadan önce baytlardan saklanmaması gerekeni siler (ör. biyometrik şablon).
     *
     * @return array{0:string, 1:?string} [baytlar, kayıt notu]
     */
    protected function redact(string $bytes): array
    {
        return [$bytes, null];
    }

    private function flush(array &$c, string $dir, string $reason): ?int
    {
        $bytes = $c['buf'][$dir];

        if ($bytes === '' && ! ($reason === 'baglanti_kaydi')) {
            return null;
        }

        $truncated = $c['trunc'][$dir];
        $c['buf'][$dir] = '';
        $c['trunc'][$dir] = false;
        [$bytes, $note] = $this->redact($bytes);

        $direction = $dir === 'u' ? 'upstream_to_device' : ($c['relay'] && $c['up_state'] !== 'failed' ? 'device_to_upstream' : 'device_to_bridge');
        $local = $this->port ?: null;

        $id = $this->store->store(
            $bytes, $direction, $c['id'], $c['ip'], $c['port'], $local, $truncated, self::parseHttp($bytes), $reason,
            $c['relay'] ? $this->upstreamLabel() : null, $c['relay'] ? $c['up_status'] : null, $note,
        );

        $this->packets++;

        return $id;
    }

    private function relayFailed(array &$c, string $status): void
    {
        if ($c['up']) {
            @fclose($c['up']);
        }
        $c['up'] = null;
        $c['up_state'] = 'failed';
        $c['up_status'] = $status;
        $c['toUp'] = '';
        $this->noteRelayError($status);
    }

    private function noteRelayError(string $status): void
    {
        $why = match ($status) {
            'refused' => 'bağlantı reddedildi',
            'timeout' => 'zaman aşımı',
            'host_unreachable' => 'ağ yolu yok',
            'network_unreachable' => 'ağ ulaşılamaz',
            default => 'bağlantı kurulamadı',
        };
        $this->lastRelayError = 'Aktarılamadı: '.$this->upstreamLabel().' '.$why.' ('.now()->format('H:i:s').')';
    }

    private function finish(string $id, string $reason): void
    {
        $c = &$this->conns[$id];

        foreach (['d', 'u'] as $dir) {
            $this->flush($c, $dir, $reason);
        }

        // Hiç bayt gelmeden kapanan bağlantı da iz bırakır (cihaz bağlandı mı, aktarma başarılı mı?).
        // İstisna: bu makinenin kendi "port dinleniyor mu?" yoklaması (127.0.0.1 / ::1) kayıt oluşturmaz.
        if ($c['started'] === $c['last'] && ! in_array($c['ip'], ['127.0.0.1', '::1'], true)) {
            $this->flush($c, 'd', 'baglanti_kaydi');
        }

        @fclose($c['client']);
        if ($c['up']) {
            @fclose($c['up']);
        }
        unset($c);
        unset($this->conns[$id]);
    }

    /** İstek tamamlandı mı? (başlıklar + Content-Length kadar gövde; chunked ise son parça) */
    public static function httpComplete(string $buf): bool
    {
        if (! self::looksLikeHttpRequest($buf)) {
            return false;
        }

        $end = strpos($buf, "\r\n\r\n");
        if ($end === false) {
            return false;
        }

        $head = substr($buf, 0, $end);
        $body = (string) substr($buf, $end + 4);

        if (preg_match('/^content-length:\s*(\d+)/im', $head, $m)) {
            return strlen($body) >= (int) $m[1];
        }

        if (preg_match('/^transfer-encoding:\s*chunked/im', $head)) {
            return str_ends_with($body, "0\r\n\r\n");
        }

        return true;
    }

    public static function looksLikeHttpRequest(string $buf): bool
    {
        return (bool) preg_match('/^(GET|POST|PUT|HEAD|OPTIONS|DELETE|PATCH) \S+ HTTP\/1\.[01]\r?\n/', $buf);
    }

    /** İstek ya da yanıtı parçalar: ilk satır + başlıklar + gövde. HTTP değilse null. */
    public static function parseHttp(string $buf): ?array
    {
        $isRequest = self::looksLikeHttpRequest($buf);
        $isResponse = (bool) preg_match('/^HTTP\/1\.[01] \d{3}/', $buf);

        if (! $isRequest && ! $isResponse) {
            return null;
        }

        $end = strpos($buf, "\r\n\r\n");
        $head = $end === false ? $buf : substr($buf, 0, $end);
        $body = $end === false ? '' : (string) substr($buf, $end + 4);
        $lines = preg_split('/\r?\n/', $head) ?: [];
        $first = (string) array_shift($lines);

        if ($isRequest) {
            [$method, $path] = array_pad(explode(' ', $first, 3), 2, '');
        } else {
            $method = 'YANIT';
            $path = $first;
        }

        return ['method' => $method, 'path' => $path, 'headers' => implode("\n", $lines), 'body' => $body];
    }

    /** @return array{0:string, 1:string} Türkçe mesaj + öneri */
    public static function bindError(int $port, int $errno, string $errstr): array
    {
        $text = strtolower($errstr);

        return match (true) {
            in_array($errno, [48, 98], true) || str_contains($text, 'in use') => [
                "{$port} portu başka bir program tarafından kullanılıyor.",
                "Bu Mac'te {$port} portunu dinleyen başka bir yazılım var (ör. PDKS programı). Farklı bir port seçin ve cihaz menüsündeki push portunu da aynı değere çevirin.",
            ],
            in_array($errno, [13, 1], true) || str_contains($text, 'permission') => [
                "{$port} portu dinlenemedi: izin yok.",
                '1024\'ün altındaki portlar yönetici izni ister; 7005 gibi yüksek bir port seçin.',
            ],
            in_array($errno, [49, 99], true) || str_contains($text, 'assign requested address') => [
                'Dinleme adresi bu bilgisayarda yok.',
                'Ağ bağlantısını kontrol edin.',
            ],
            default => ["{$port} portu dinlenemedi ({$errno} {$errstr}).", 'Uygulamayı yeniden başlatıp tekrar deneyin.'],
        };
    }
}
