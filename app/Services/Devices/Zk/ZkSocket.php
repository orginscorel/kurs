<?php

namespace App\Services\Devices\Zk;

use App\Services\Devices\Zk\Exceptions\ZkConnectionException;
use App\Services\Devices\Zk\Exceptions\ZkTimeoutException;
use App\Services\Devices\Network\SocketFailure;
use App\Services\Devices\Network\TcpProbe;

/**
 * SOKET KATMANI — tek sorumluluk: bayt gönder / bayt al, her zaman zaman aşımıyla.
 *
 * Saf PHP (stream_socket_client); ek PECL uzantısı gerekmez, sunucuda da Mac masaüstü
 * paketinde de aynı çalışır. Protokolden hiç haberi yoktur.
 *
 * TCP: akış tabanlı → readExactly() istenen bayt sayısını toplayana kadar okur.
 * UDP: datagram → readDatagram() tek paket alır (kısmi okuma diye bir şey yoktur).
 */
class ZkSocket
{
    /** Yalnız bu (ZKTeco) sürücünün notu; genel soket metinleri hiçbir porta/markaya atıf yapmaz. */
    private const DRIVER_NOTE = 'Not: bu ZKTeco sürücüsüdür (ZKTeco fabrika portu 4370). Cihazınız ZKTeco protokolü konuşmuyorsa (ör. Perkotek YT33 / FK Dynamic Face) Terminal Köprüsü › Sürücü alanından doğru sürücüyü seçin.';

    /** @var resource|null */
    private $stream = null;

    private float $readTimeout;

    private function __construct($stream, float $readTimeout, public readonly string $transport, public readonly string $target)
    {
        $this->stream = $stream;
        $this->readTimeout = $readTimeout;
    }

    /**
     * @param  string  $transport  'tcp' | 'udp'
     * @param  float  $connectTimeout  bağlanma için saniye (varsayılan 3)
     * @param  float  $readTimeout  her okuma için saniye (varsayılan 10)
     */
    public static function connect(string $host, int $port, string $transport = 'tcp', float $connectTimeout = 3.0, float $readTimeout = 10.0): self
    {
        $transport = $transport === 'udp' ? 'udp' : 'tcp';
        $target = "{$transport}://{$host}:{$port}";

        if ($transport === 'tcp') {
            // Soket aşaması ortak sınamadan geçer: errno → Türkçe neden, macOS Yerel Ağ izni ipucu, yerel IP.
            [$stream, $probe] = app(TcpProbe::class)->open($host, $port, max(0.2, $connectTimeout));

            if ($stream === null) {
                throw new ZkConnectionException(
                    'Cihaza bağlanılamadı: '.$probe->message.'.',
                    $probe->hint.' '.self::DRIVER_NOTE,
                    ['hata_no' => $probe->errno, 'hata' => $probe->errstr, 'hedef' => $target, 'neden' => $probe->failure?->value, 'macos_yerel_ag_izni_olasi' => $probe->macLocalNetworkSuspect],
                );
            }
        } else {
            $errNo = 0;
            $errStr = '';
            $stream = @stream_socket_client($target, $errNo, $errStr, max(0.2, $connectTimeout), STREAM_CLIENT_CONNECT);

            if ($stream === false) {
                $failure = SocketFailure::classify((int) $errNo, (string) $errStr);
                throw new ZkConnectionException(
                    'Cihaza bağlanılamadı: '.$failure->title()." ({$host}:{$port}/udp).",
                    $failure->hint($host, $port, false).' '.self::DRIVER_NOTE,
                    ['hata_no' => $errNo, 'hata' => $errStr, 'hedef' => $target, 'neden' => $failure->value],
                );
            }
        }

        stream_set_blocking($stream, true);
        self::applyTimeout($stream, $readTimeout);

        return new self($stream, $readTimeout, $transport, $target);
    }

    /** Test/sahte cihaz için: hazır bir akışı sar. */
    public static function wrap($stream, float $readTimeout = 10.0, string $transport = 'tcp', string $target = 'test'): self
    {
        stream_set_blocking($stream, true);
        self::applyTimeout($stream, $readTimeout);

        return new self($stream, $readTimeout, $transport, $target);
    }

    public function setReadTimeout(float $seconds): void
    {
        $this->readTimeout = max(0.2, $seconds);
        if ($this->stream) {
            self::applyTimeout($this->stream, $this->readTimeout);
        }
    }

    public function write(string $bytes): void
    {
        $stream = $this->stream();
        $total = strlen($bytes);
        $sent = 0;
        $deadline = microtime(true) + $this->readTimeout;

        while ($sent < $total) {
            $n = @fwrite($stream, substr($bytes, $sent));
            if ($n === false || $n === 0) {
                if (microtime(true) >= $deadline) {
                    throw new ZkTimeoutException('Cihaza veri gönderilemedi (süre doldu).', 'Cihazı yeniden başlatıp tekrar deneyin.');
                }
                usleep(20000);

                continue;
            }
            $sent += $n;
        }
    }

    /** TCP: tam olarak $length bayt toplanana kadar oku. Süre dolarsa zaman aşımı hatası. */
    public function readExactly(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $stream = $this->stream();
        $buffer = '';
        $deadline = microtime(true) + $this->readTimeout;

        while (strlen($buffer) < $length) {
            $chunk = @fread($stream, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($stream);
                if (! empty($meta['timed_out']) || microtime(true) >= $deadline) {
                    throw new ZkTimeoutException(
                        'Cihaz yanıt vermedi (süre doldu).',
                        'Cihaz meşgul olabilir (biri parmak okutuyor) ya da başka bir program cihaza bağlı olabilir. Birkaç saniye sonra tekrar deneyin.',
                        ['beklenen' => $length, 'alinan' => strlen($buffer)],
                    );
                }
                if (feof($stream)) {
                    throw new ZkConnectionException(
                        'Cihaz bağlantıyı kapattı.',
                        'Cihaz aynı anda yalnız bir bağlantı kabul eder. Başka bir bilgisayarda/programda açık bağlantı varsa kapatın.',
                        ['beklenen' => $length, 'alinan' => strlen($buffer)],
                    );
                }
                usleep(10000);

                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** UDP: tek datagram oku (en çok $max bayt). */
    public function readDatagram(int $max = 4096): string
    {
        $stream = $this->stream();
        $data = @fread($stream, $max);

        if ($data === false || $data === '') {
            $meta = stream_get_meta_data($stream);
            if (! empty($meta['timed_out'])) {
                throw new ZkTimeoutException(
                    'Cihaz yanıt vermedi (süre doldu).',
                    'UDP bağlantısında yanıt kaybolmuş olabilir. Cihaz menüsünden TCP desteğini açın ya da tekrar deneyin.',
                );
            }
            throw new ZkTimeoutException('Cihazdan boş yanıt geldi.', 'Cihazı yeniden başlatıp tekrar deneyin.');
        }

        return $data;
    }

    public function close(): void
    {
        if ($this->stream) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public function isOpen(): bool
    {
        return $this->stream !== null;
    }

    private function stream()
    {
        if ($this->stream === null) {
            throw new ZkConnectionException('Bağlantı kapalı.', 'Önce cihaza bağlanın.');
        }

        return $this->stream;
    }

    private static function applyTimeout($stream, float $seconds): void
    {
        $seconds = max(0.2, $seconds);
        stream_set_timeout($stream, (int) floor($seconds), (int) round(($seconds - floor($seconds)) * 1_000_000));
    }
}
