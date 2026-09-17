<?php

namespace Tests\Support;

use App\Services\Devices\Zk\ZkProtocol;
use Carbon\CarbonImmutable;

/**
 * SAHTE BİYOMETRİK TERMİNAL — gerçek soket üzerinden ZKTeco protokolünü konuşur.
 *
 * Cihaz elimizde olmadığı için köprü bununla kanıtlanır: ayrı bir PHP süreci 127.0.0.1'de
 * dinler, istemci gerçekten TCP/UDP ile bağlanır, el sıkışır, kimlik doğrular, tamponlu
 * ya da düz yoldan veri okur. Böylece soket + paket + komut + çözücü katmanlarının
 * tamamı uçtan uca çalışır.
 *
 * Senaryo seçenekleri:
 *   transport     tcp | udp
 *   comm_key      null ya da sayı (verilirse CMD_CONNECT'e ACK_UNAUTH döner)
 *   records       kaç yoklama kaydı üretilsin
 *   record_size   40 | 16 | 8
 *   users         kullanıcı listesi [{uid, user_id, name, card, privilege}]
 *   user_size     72 | 28
 *   stream        'inline' (CMD_DATA) | 'prepare' (PREPARE_DATA + DATA + ACK_OK)
 *   buffered      false ise CMD_DATA_WRRQ'ya hata döner → istemci düz okumaya düşer
 *   base_time     kayıtların başlangıç zamanı (Y-m-d H:i:s)
 */
class FakeZkDevice
{
    public const SESSION_ID = 0x1F42;

    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options + [
            'transport' => 'tcp',
            'comm_key' => null,
            'records' => 5,
            'record_size' => 40,
            'users' => [],
            'user_size' => 72,
            'stream' => 'inline',
            'buffered' => true,
            'base_time' => '2026-09-15 08:00:00',
            'serial' => 'YT33-TEST-0001',
            'device_name' => 'YT33',
            'firmware' => 'Ver 6.60 Apr 21 2020',
        ];

        if ($this->options['users'] === []) {
            $this->options['users'] = [
                ['uid' => 1, 'user_id' => '1001', 'name' => 'Ayse Yilmaz', 'card' => 1234567, 'privilege' => 0],
                ['uid' => 2, 'user_id' => '1002', 'name' => 'Mehmet Kaya', 'card' => 0, 'privilege' => 0],
                ['uid' => 3, 'user_id' => '1003', 'name' => 'Zeynep Ak', 'card' => 0, 'privilege' => 14],
            ];
        }
    }

    // ================================================================= süreç yönetimi

    /**
     * Sahte cihazı ayrı bir süreçte başlatır ve dinlediği portu döner.
     *
     * @return array{0:resource, 1:int} [süreç, port]
     */
    public static function start(array $options = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'zkfake_').'.json';
        file_put_contents($file, json_encode($options));

        $php = PHP_BINARY;
        $script = __DIR__.'/fake-zk-device.php';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open([$php, $script, $file], $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new \RuntimeException('Sahte cihaz süreci başlatılamadı.');
        }

        stream_set_timeout($pipes[1], 10);
        $line = fgets($pipes[1]);

        if (! is_string($line) || ! preg_match('/^PORT=(\d+)/', trim($line), $m)) {
            $err = stream_get_contents($pipes[2]);
            proc_terminate($process);
            throw new \RuntimeException('Sahte cihaz portu okunamadı: '.trim((string) $line).' '.$err);
        }

        return [$process, (int) $m[1], $pipes];
    }

    public static function stop($process, array $pipes = []): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($process)) {
            @proc_terminate($process);
            @proc_close($process);
        }
    }

    // ================================================================= sunucu döngüsü

    public function serve(): void
    {
        $this->options['transport'] === 'udp' ? $this->serveUdp() : $this->serveTcp();
    }

    /** Portu yazdırıp tek istemciye hizmet eder, sonra çıkar. */
    private function serveTcp(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errNo, $errStr);
        if (! $server) {
            throw new \RuntimeException("Dinlenemedi: {$errStr}");
        }

        $this->announce($server);

        $client = @stream_socket_accept($server, 10);
        if (! $client) {
            return;
        }
        stream_set_timeout($client, 10);

        while (! feof($client)) {
            $top = $this->readExactly($client, 8);
            if ($top === null) {
                return;
            }

            $length = unpack('vm1/vm2/Vlen', $top)['len'];
            $frame = $this->readExactly($client, $length);
            if ($frame === null) {
                return;
            }

            $head = unpack('vcommand/vchecksum/vsession/vreply', substr($frame, 0, 8));
            $payload = substr($frame, 8);

            foreach ($this->respond($head['command'], $payload) as $response) {
                fwrite($client, ZkProtocol::wrapTcp($this->frame($response[0], $response[1], $head['reply'])));
            }

            if ($head['command'] === ZkProtocol::CMD_EXIT) {
                break;
            }
        }

        fclose($client);
        fclose($server);
    }

    private function serveUdp(): void
    {
        $server = stream_socket_server('udp://127.0.0.1:0', $errNo, $errStr, STREAM_SERVER_BIND);
        if (! $server) {
            throw new \RuntimeException("Dinlenemedi: {$errStr}");
        }

        $this->announce($server);
        stream_set_timeout($server, 10);

        while (true) {
            $peer = '';
            $datagram = @stream_socket_recvfrom($server, 65535, 0, $peer);
            if ($datagram === false || $datagram === '') {
                return;
            }

            $head = unpack('vcommand/vchecksum/vsession/vreply', substr($datagram, 0, 8));
            $payload = substr($datagram, 8);

            foreach ($this->respond($head['command'], $payload) as $response) {
                stream_socket_sendto($server, $this->frame($response[0], $response[1], $head['reply']), 0, $peer);
            }

            if ($head['command'] === ZkProtocol::CMD_EXIT) {
                break;
            }
        }

        fclose($server);
    }

    private function announce($server): void
    {
        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fwrite(STDOUT, "PORT={$port}\n");
        fflush(STDOUT);
    }

    private function readExactly($stream, int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($stream, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function frame(int $command, string $data, int $replyId): string
    {
        $unchecked = pack('vvvv', $command, 0, self::SESSION_ID, $replyId).$data;

        return pack('vvvv', $command, ZkProtocol::checksum($unchecked), self::SESSION_ID, $replyId).$data;
    }

    // ================================================================= protokol yanıtları

    /**
     * Bir komuta karşılık gönderilecek çerçeveler.
     *
     * @return list<array{0:int, 1:string}>
     */
    public function respond(int $command, string $payload): array
    {
        return match ($command) {
            ZkProtocol::CMD_CONNECT => [[$this->options['comm_key'] !== null ? ZkProtocol::CMD_ACK_UNAUTH : ZkProtocol::CMD_ACK_OK, '']],
            ZkProtocol::CMD_AUTH => [[$this->checkAuth($payload) ? ZkProtocol::CMD_ACK_OK : ZkProtocol::CMD_ACK_UNAUTH, '']],
            ZkProtocol::CMD_OPTIONS_RRQ => [$this->parameter($payload)],
            ZkProtocol::CMD_GET_VERSION => [[ZkProtocol::CMD_ACK_OK, $this->options['firmware']."\0"]],
            ZkProtocol::CMD_GET_FREE_SIZES => [[ZkProtocol::CMD_ACK_OK, $this->sizesPayload()]],
            ZkProtocol::CMD_GET_TIME => [[ZkProtocol::CMD_ACK_OK, pack('V', ZkProtocol::encodeTime(CarbonImmutable::parse($this->options['base_time'])))]],
            ZkProtocol::CMD_DATA_WRRQ => $this->bufferRequest($payload),
            ZkProtocol::CMD_DATA_RDY => $this->chunk($payload),
            ZkProtocol::CMD_ATTLOG_RRQ => $this->stream($this->attendanceData()),
            ZkProtocol::CMD_USERTEMP_RRQ => $this->stream($this->userData()),
            default => [[ZkProtocol::CMD_ACK_OK, '']],
        };
    }

    private function checkAuth(string $payload): bool
    {
        return $payload === ZkProtocol::commKey((int) $this->options['comm_key'], self::SESSION_ID);
    }

    private function parameter(string $payload): array
    {
        $name = rtrim($payload, "\0");

        $value = match ($name) {
            '~SerialNumber' => $this->options['serial'],
            '~DeviceName' => $this->options['device_name'],
            '~Platform' => 'ZMM220_TFT',
            '~ZKFPVersion' => '10',
            'MAC' => '00:17:61:01:02:03',
            default => null,
        };

        return $value === null
            ? [ZkProtocol::CMD_ACK_ERROR, '']
            : [ZkProtocol::CMD_ACK_OK, "{$name}={$value}\0"];
    }

    private function sizesPayload(): string
    {
        $ints = array_fill(0, 23, 0);
        $ints[4] = count($this->options['users']);   // ofset 16: kullanıcı sayısı
        $ints[6] = count($this->options['users']);   // ofset 24: parmak sayısı
        $ints[8] = (int) $this->options['records'];  // ofset 32: kayıt sayısı
        $ints[15] = 3000;                            // ofset 60: kullanıcı kapasitesi
        $ints[16] = 100000;                          // ofset 64: kayıt kapasitesi

        return implode('', array_map(fn ($i) => pack('V', $i), $ints));
    }

    /** CMD_DATA_WRRQ: ya "veri hazır, boyutu şu" der ya da desteklemediğini bildirir. */
    private function bufferRequest(string $payload): array
    {
        if (! $this->options['buffered']) {
            return [[ZkProtocol::CMD_ACK_ERROR, '']];
        }

        $inner = unpack('cone/vcommand/Vfct/Vext', $payload);
        $this->pending = $inner['command'] === ZkProtocol::CMD_USERTEMP_RRQ ? $this->userData() : $this->attendanceData();

        $size = strlen($this->pending);

        // "data stat": 1 bayt 0 + boyut + boyut + sağlama benzeri
        return [[ZkProtocol::CMD_ACK_OK, "\0".pack('V', $size).pack('V', $size).pack('V', 0)]];
    }

    private string $pending = '';

    private function chunk(string $payload): array
    {
        $req = unpack('Vstart/Vsize', $payload);
        $slice = substr($this->pending, $req['start'], $req['size']);

        return $this->stream($slice, false);
    }

    /**
     * Veriyi ya tek CMD_DATA çerçevesiyle ya da PREPARE_DATA + DATA + ACK_OK akışıyla verir.
     *
     * @return list<array{0:int, 1:string}>
     */
    private function stream(string $data, bool $withHeader = true): array
    {
        if ($this->options['stream'] !== 'prepare') {
            return [[ZkProtocol::CMD_DATA, $data]];
        }

        $frames = [[ZkProtocol::CMD_PREPARE_DATA, pack('V', strlen($data))."\x00\x10\x00\x00"]];

        // Gerçek cihaz gibi parçalara böl (tek DATA çerçevesi yetmeyebilir).
        foreach (str_split($data ?: "\0", 1024) as $part) {
            $frames[] = [ZkProtocol::CMD_DATA, $part];
        }
        $frames[] = [ZkProtocol::CMD_ACK_OK, ''];

        return $frames;
    }

    // ================================================================= veri üretimi

    /** İlk 4 bayt toplam boyut, ardından kayıtlar. */
    public function attendanceData(): string
    {
        $size = (int) $this->options['record_size'];
        $base = CarbonImmutable::parse($this->options['base_time']);
        $users = $this->options['users'];
        $body = '';

        for ($i = 0; $i < (int) $this->options['records']; $i++) {
            $user = $users[$i % count($users)];
            $time = $base->addMinutes($i * 7);
            $punch = $i % 2;                  // 0 giriş, 1 çıkış
            $verify = 1;                      // parmak izi

            $body .= match ($size) {
                40 => pack('v', $user['uid'])
                    .str_pad((string) $user['user_id'], 24, "\0")
                    .chr($verify)
                    .pack('V', ZkProtocol::encodeTime($time))
                    .chr($punch)
                    .str_repeat("\0", 8),
                16 => pack('V', (int) $user['user_id'])
                    .pack('V', ZkProtocol::encodeTime($time))
                    .chr($verify).chr($punch).str_repeat("\0", 2).pack('V', 0),
                8 => pack('v', $user['uid'])
                    .chr($verify)
                    .pack('V', ZkProtocol::encodeTime($time))
                    .chr($punch),
                default => '',
            };
        }

        return pack('V', strlen($body)).$body;
    }

    public function userData(): string
    {
        $size = (int) $this->options['user_size'];
        $body = '';

        foreach ($this->options['users'] as $user) {
            $body .= $size === 72
                ? pack('v', $user['uid'])
                    .chr($user['privilege'] ?? 0)
                    .str_pad('', 8, "\0")
                    .str_pad((string) $user['name'], 24, "\0")
                    .pack('V', (int) ($user['card'] ?? 0))
                    .chr(0)
                    .str_repeat("\0", 7)
                    .chr(0)
                    .str_pad((string) $user['user_id'], 24, "\0")
                : pack('v', $user['uid'])
                    .chr($user['privilege'] ?? 0)
                    .str_pad('', 5, "\0")
                    .str_pad(substr((string) $user['name'], 0, 8), 8, "\0")
                    .pack('V', (int) ($user['card'] ?? 0))
                    .chr(0)
                    .chr(0)
                    .pack('v', 0)
                    .pack('V', (int) $user['user_id']);
        }

        return pack('V', strlen($body)).$body;
    }
}
