<?php

namespace App\Services\Devices\Zk;

use App\Services\Devices\Zk\Exceptions\ZkAuthException;
use App\Services\Devices\Zk\Exceptions\ZkProtocolException;

/**
 * KOMUT KATMANI — oturum yönetimi, komut gönderme, büyük veri okuma.
 *
 * Bu sınıf yalnız "komut gönder / çerçeve al" bilir; kayıt biçimlerini bilmez (ZkCodec),
 * bayt taşımayı da bilmez (ZkSocket). Böylece sahte cihazla uçtan uca test edilebilir.
 */
class ZkClient
{
    private int $sessionId = 0;

    /** pyzk ile aynı: ilk komutta bir artırılır ve 0'a düşer → CMD_CONNECT reply_id = 0 gider. */
    private int $replyId = ZkProtocol::USHRT_MAX - 1;

    private bool $connected = false;

    public function __construct(private readonly ZkSocket $socket, private readonly ?string $commKey = null) {}

    public static function open(ZkConnectionSettings $settings): self
    {
        $socket = ZkSocket::connect($settings->host, $settings->port, $settings->transport, $settings->connectTimeout, $settings->readTimeout);

        $client = new self($socket, $settings->commKey);
        $client->connect();

        return $client;
    }

    public function isTcp(): bool
    {
        return $this->socket->transport === 'tcp';
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    /** CMD_CONNECT + gerekiyorsa CMD_AUTH. */
    public function connect(): void
    {
        $packet = $this->send(ZkProtocol::CMD_CONNECT);
        $this->sessionId = $packet->sessionId;

        if ($packet->command === ZkProtocol::CMD_ACK_UNAUTH) {
            $this->authenticate();
            $this->connected = true;

            return;
        }

        if (! $packet->isSuccess()) {
            throw new ZkProtocolException(
                'Cihaz bağlantı isteğini reddetti (yanıt: '.$packet->commandName().').',
                'Cihazda başka bir bağlantı açık olabilir ya da aygıt yazılımı farklı bir protokol konuşuyor. Cihazı yeniden başlatıp tekrar deneyin.',
            );
        }

        $this->connected = true;
    }

    private function authenticate(): void
    {
        if ($this->commKey === null || $this->commKey === '' || ! ctype_digit(ltrim($this->commKey, '-'))) {
            throw new ZkAuthException(
                'Cihaz iletişim şifresi (comm key) istiyor ama girilmedi.',
                'Cihaz menüsü: Comm (İletişim) > İletişim Şifresi. Oradaki sayıyı --sifre= ile verin ya da cihaz kaydına yazın. Şifre kapalıysa cihazda değeri 0 yapın.',
            );
        }

        $packet = $this->send(ZkProtocol::CMD_AUTH, ZkProtocol::commKey((int) $this->commKey, $this->sessionId));

        if (! $packet->isSuccess()) {
            throw new ZkAuthException(
                'Cihaz iletişim şifresini reddetti.',
                'Girilen şifre cihazdakiyle aynı değil. Cihaz menüsü: Comm (İletişim) > İletişim Şifresi. Şifre kapalıysa 0 girin.',
                ['yanit' => $packet->commandName()],
            );
        }
    }

    /** Tek komut gönderip tek çerçeve alır. Cevabın başarılı olup olmadığını çağıran yorumlar. */
    public function send(int $command, string $payload = ''): ZkPacket
    {
        [$frame, $this->replyId] = ZkProtocol::buildCommandFrame($command, $payload, $this->sessionId, $this->replyId);

        $this->socket->write($this->isTcp() ? ZkProtocol::wrapTcp($frame) : $frame);

        $packet = $this->receive();
        $this->replyId = $packet->replyId;   // pyzk: sonraki komut cihazın döndürdüğü reply_id + 1 ile gider

        return $packet;
    }

    /** Komutu gönderir, başarısızsa Türkçe protokol hatası fırlatır. */
    public function command(int $command, string $payload = '', string $what = 'komut'): ZkPacket
    {
        $packet = $this->send($command, $payload);

        if (! $packet->isSuccess()) {
            throw new ZkProtocolException(
                "Cihaz isteği yerine getirmedi ({$what}).",
                'Bu aygıt yazılımı ilgili komutu desteklemiyor olabilir. docs/CIHAZ-KOPRUSU.md › Sorun giderme',
                ['komut' => $command, 'yanit' => $packet->commandName()],
            );
        }

        return $packet;
    }

    /** Bir çerçeve alır (TCP'de taşıyıcı başlık + gövde; UDP'de tek datagram). */
    public function receive(): ZkPacket
    {
        if (! $this->isTcp()) {
            return ZkPacket::parse($this->socket->readDatagram(ZkProtocol::MAX_CHUNK_UDP + 64));
        }

        $length = ZkProtocol::readTcpTop($this->socket->readExactly(8));

        return ZkPacket::parse($this->socket->readExactly($length));
    }

    /**
     * BÜYÜK VERİ OKUMA — önce "buffer" yolu (CMD_DATA_WRRQ/CMD_DATA_RDY), desteklenmiyorsa
     * düz okuma yolu (komutu doğrudan gönder, CMD_PREPARE_DATA + CMD_DATA akışını topla).
     *
     * @return string ham veri kümesi (ilk 4 bayt: toplam boyut)
     */
    public function readData(int $command, int $fct = 0): string
    {
        try {
            return $this->readWithBuffer($command, $fct);
        } catch (ZkProtocolException $e) {
            if ($e->context['yeniden_dene'] ?? false) {
                return $this->readPlain($command);
            }
            throw $e;
        }
    }

    /** ZKTeco "free data" akışı: 1503 ile boyutu öğren, 1504 ile parça parça oku, 1502 ile serbest bırak. */
    public function readWithBuffer(int $command, int $fct = 0): string
    {
        $packet = $this->send(ZkProtocol::CMD_DATA_WRRQ, ZkProtocol::bufferRequest($command, $fct));

        if (! $packet->isSuccess()) {
            // Eski aygıt yazılımları bu komutu bilmez → çağıran düz okumaya düşsün.
            throw new ZkProtocolException(
                'Cihaz tamponlu okumayı desteklemiyor.',
                'Sorun değil: düz okuma yolu denenecek.',
                ['yeniden_dene' => true, 'yanit' => $packet->commandName()],
            );
        }

        // Veri küçükse doğrudan bu pakette gelir.
        if ($packet->command === ZkProtocol::CMD_DATA) {
            return $packet->data;
        }

        if (strlen($packet->data) < 5) {
            throw new ZkProtocolException('Cihaz veri boyutunu bildirmedi.', 'Cihazı yeniden başlatıp tekrar deneyin.');
        }

        $size = unpack('V', substr($packet->data, 1, 4))[1];
        if ($size <= 0) {
            $this->freeData();

            return '';
        }

        $maxChunk = $this->isTcp() ? ZkProtocol::MAX_CHUNK_TCP : ZkProtocol::MAX_CHUNK_UDP;
        $data = '';
        $start = 0;

        while ($start < $size) {
            $length = min($maxChunk, $size - $start);
            $data .= $this->readChunk($start, $length);
            $start += $length;
        }

        $this->freeData();

        return $data;
    }

    /** Tek parça oku (CMD_DATA_RDY). */
    private function readChunk(int $start, int $size): string
    {
        $packet = $this->send(ZkProtocol::CMD_DATA_RDY, ZkProtocol::chunkRequest($start, $size));

        return $this->collect($packet, $size);
    }

    /** Eski yol: komutu doğrudan gönder, CMD_PREPARE_DATA + CMD_DATA akışını topla. */
    public function readPlain(int $command, string $payload = ''): string
    {
        $packet = $this->command($command, $payload, 'veri okuma');

        return $this->collect($packet, null);
    }

    /**
     * Bir cevaptan başlayarak veriyi toplar.
     * - CMD_DATA: veri bu pakettedir.
     * - CMD_PREPARE_DATA: ilk 4 bayt boyut; ardından CMD_DATA çerçeveleri, en sonda CMD_ACK_OK gelir.
     */
    private function collect(ZkPacket $packet, ?int $expected): string
    {
        if ($packet->command === ZkProtocol::CMD_DATA) {
            return $packet->data;
        }

        if ($packet->command !== ZkProtocol::CMD_PREPARE_DATA) {
            throw new ZkProtocolException(
                'Cihazdan beklenmeyen yanıt geldi ('.$packet->commandName().').',
                'docs/CIHAZ-KOPRUSU.md › Sorun giderme',
            );
        }

        $size = strlen($packet->data) >= 4 ? unpack('V', substr($packet->data, 0, 4))[1] : ($expected ?? 0);
        $data = '';
        $guard = 0;

        while (strlen($data) < $size) {
            if (++$guard > 10000) {
                throw new ZkProtocolException('Cihaz veri akışını bitirmedi.', 'Cihazı yeniden başlatıp tekrar deneyin.');
            }

            $next = $this->receive();

            if ($next->command === ZkProtocol::CMD_DATA) {
                $data .= $next->data;

                continue;
            }

            if ($next->command === ZkProtocol::CMD_ACK_OK) {
                return $data;   // cihaz veriyi erken bitirdi
            }

            throw new ZkProtocolException(
                'Veri akışı beklenmedik bir yanıtla kesildi ('.$next->commandName().').',
                'Cihazda başka bir bağlantı açık olabilir; kapatıp tekrar deneyin.',
            );
        }

        // Akışın sonundaki CMD_ACK_OK çerçevesini yut (yoksa sonraki komut kayar).
        $tail = $this->receive();
        if ($tail->command !== ZkProtocol::CMD_ACK_OK && $tail->command !== ZkProtocol::CMD_DATA) {
            throw new ZkProtocolException(
                'Veri akışı düzgün kapanmadı ('.$tail->commandName().').',
                'Cihazı yeniden başlatıp tekrar deneyin.',
            );
        }

        return substr($data, 0, $size);
    }

    public function freeData(): void
    {
        $this->send(ZkProtocol::CMD_FREE_DATA);
    }

    public function disableDevice(): void
    {
        $this->send(ZkProtocol::CMD_DISABLEDEVICE);
    }

    public function enableDevice(): void
    {
        $this->send(ZkProtocol::CMD_ENABLEDEVICE);
    }

    /** CMD_EXIT + soketi kapat. Hata olsa bile soket mutlaka kapanır. */
    public function close(): void
    {
        if ($this->connected && $this->socket->isOpen()) {
            try {
                $this->send(ZkProtocol::CMD_EXIT);
            } catch (\Throwable) {
                // kapanışta hata yutulur; önemli olan soketin kapanması
            }
        }

        $this->connected = false;
        $this->socket->close();
    }
}
