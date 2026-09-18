<?php

namespace App\Services\Devices\Network;

use Illuminate\Support\Facades\Log;

/**
 * AĞ / SOKET SINAMASI — cihaza HİÇBİR BAYT göndermeden yalnız TCP bağlantısı açar ve kapatır.
 *
 * Neden ayrı? "Cihaza bağlanılamadı" iki çok farklı durumu gizliyordu: (1) soket hiç açılmadı
 * (ağ, güvenlik duvarı, macOS Yerel Ağ izni) ve (2) soket açıldı ama cihaz beklenen protokolü
 * konuşmadı. Bu sınıf yalnız (1)'i ölçer; süre, yerel kaynak IP (= köprü IP'si), errno ve Türkçe neden.
 *
 * Uygulama SÜRECİNİN kendisinden ölçer: masaüstünde gömülü php'nin LAN'a çıkıp çıkamadığını
 * gösterir (Terminal.app'teki `nc` başarısı bunu kanıtlamaz; macOS izni uygulama başınadır).
 */
class TcpProbe
{
    /** Testlerde işletim sistemi ailesini sabitlemek için (null = PHP_OS_FAMILY). */
    public static ?string $osFamily = null;

    public function probe(string $host, int $port, float $timeout = 3.0): TcpProbeResult
    {
        [$stream, $result] = $this->open($host, $port, $timeout);

        if ($stream !== null) {
            @fclose($stream);
        }

        return $result;
    }

    /**
     * Soketi açar ve AÇIK bırakır (ham tanılama ve sürücüler için). Başarısızsa akış null döner.
     *
     * @return array{0: resource|null, 1: TcpProbeResult}
     */
    public function open(string $host, int $port, float $timeout = 3.0): array
    {
        $timeout = max(0.2, $timeout);
        $errno = 0;
        $errstr = '';
        $started = hrtime(true);

        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);

        if ($stream === false) {
            // PHP zaman aşımında errno'yu bazen 0 bırakır; süre dolduysa zaman aşımı say
            if ($errno === 0 && $errstr === '' && $ms >= (int) ($timeout * 1000) - 50) {
                $errstr = 'Connection timed out';
            }

            $failure = SocketFailure::classify((int) $errno, (string) $errstr);
            $macLan = $this->isMac() && $this->isPrivate($host);
            $result = new TcpProbeResult(
                connected: false, host: $host, port: $port, durationMs: $ms,
                errno: (int) $errno, errstr: (string) $errstr, failure: $failure,
                macLocalNetworkSuspect: $failure->mayBeLocalNetworkPermission($macLan),
                message: $failure->title()." ({$host}:{$port}/tcp)",
                hint: $failure->hint($host, $port, $macLan),
            );

            Log::channel('terminal')->warning('TCP sınaması başarısız', $result->toArray());

            return [null, $result];
        }

        [$localIp, $localPort] = self::split((string) @stream_socket_get_name($stream, false));

        $result = new TcpProbeResult(
            connected: true, host: $host, port: $port, durationMs: $ms,
            localIp: $localIp, localPort: $localPort,
            message: "TCP {$port} bağlantısı açıldı ({$ms} ms).",
        );

        Log::channel('terminal')->info('TCP sınaması başarılı', ['uzak' => "{$host}:{$port}", 'yerel' => $localIp, 'sure_ms' => $ms]);

        return [$stream, $result];
    }

    public function isMac(): bool
    {
        return (self::$osFamily ?? PHP_OS_FAMILY) === 'Darwin';
    }

    public function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
    }

    /** "192.168.1.5:51234" → ['192.168.1.5', 51234] (IPv6 köşeli ayraçlı da olur). */
    public static function split(string $name): array
    {
        if ($name === '' || ! str_contains($name, ':')) {
            return [$name !== '' ? $name : null, null];
        }

        $pos = strrpos($name, ':');

        return [trim(substr($name, 0, $pos), '[]'), (int) substr($name, $pos + 1)];
    }
}
