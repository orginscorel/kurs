<?php

namespace App\Services\Devices\Discovery;

use App\Services\Devices\Zk\ZkPacket;
use App\Services\Devices\Zk\ZkProtocol;

/**
 * UDP YAYIN (broadcast) KEŞFİ — "orada kimse var mı?"
 *
 * Yönteme dair DÜRÜST NOT (tahminle yazılmadı, kaynak okundu):
 *   - pyzk (fananimi/pyzk) ve node-zklib'de yayın taraması YOKTUR; ikisi de tek adrese bağlanır.
 *   - Fabrikanın kendi arama aracı 4370/UDP üzerinde yayın paketi atar; topluluk uygulamalarında
 *     çalıştığı bilinen biçim, normal CMD_CONNECT (1000) çerçevesinin yayın adresine
 *     gönderilmesidir. UDP konuşan cihazlar kendi IP'lerinden CMD_ACK_OK ile yanıtlar.
 *   - Bu yüzden yayın keşfi BURADA YARDIMCI yoldur, tek yol değildir: asıl güvenilir yöntem
 *     PortSweep (TCP 4370 süpürme). Yayın hiçbir şey döndürmezse tarama yine sonuç verir.
 *     (Bazı aygıt yazılımları UDP'yi hiç açmaz; bazı ağ anahtarları yayını bastırır.)
 *
 * Yanıt gelmemesi HATA DEĞİLDİR; sessizce boş liste döner.
 */
final class ZkBroadcast
{
    /**
     * @param  list<string>  $broadcastAddresses  ör. ['192.168.1.255']
     * @param  float  $wait  yanıtlar için toplam bekleme (saniye)
     * @return list<array{ip:string, port:int, yanit_ms:int}>
     */
    public function discover(array $broadcastAddresses, int $port = 4370, float $wait = 1.2): array
    {
        if (! function_exists('socket_create') || $broadcastAddresses === []) {
            return [];   // ext-sockets yok → yayın atlanır, TCP süpürme yeter
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            return [];
        }

        try {
            @socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            @socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 200_000]);
            @socket_set_nonblock($socket);

            [$frame] = ZkProtocol::buildCommandFrame(ZkProtocol::CMD_CONNECT, '', 0, ZkProtocol::USHRT_MAX - 1);
            $started = microtime(true);

            foreach ($broadcastAddresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    @socket_sendto($socket, $frame, strlen($frame), 0, $address, $port);
                }
            }

            return $this->collect($socket, $port, $started, max(0.2, min($wait, 5.0)));
        } finally {
            @socket_close($socket);
        }
    }

    /** @return list<array{ip:string, port:int, yanit_ms:int}> */
    private function collect($socket, int $port, float $started, float $wait): array
    {
        $found = [];
        $deadline = $started + $wait;

        while (microtime(true) < $deadline) {
            $buffer = '';
            $from = '';
            $fromPort = 0;

            $bytes = @socket_recvfrom($socket, $buffer, 2048, 0, $from, $fromPort);

            if ($bytes === false || $bytes < 8) {
                usleep(20_000);

                continue;
            }

            $packet = ZkPacket::parse($buffer);

            // Yalnız protokolü konuşan yanıtlar sayılır (rastgele UDP gürültüsü elenir).
            if (! in_array($packet->command, [ZkProtocol::CMD_ACK_OK, ZkProtocol::CMD_ACK_UNAUTH], true)) {
                continue;
            }

            if ($from !== '' && ! isset($found[$from])) {
                $found[$from] = ['ip' => $from, 'port' => $port, 'yanit_ms' => (int) round((microtime(true) - $started) * 1000)];
            }
        }

        return array_values($found);
    }
}
