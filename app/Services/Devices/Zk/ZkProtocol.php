<?php

namespace App\Services\Devices\Zk;

use App\Services\Devices\Zk\Exceptions\ZkProtocolException;
use Carbon\CarbonImmutable;

/**
 * PAKET KATMANI — saf, durumsuz, tamamen test edilebilir.
 *
 * Komut kodları, sağlama (checksum), başlık kurma/çözme ve zaman kodlaması. Kaynak olarak
 * açık kaynak uygulamalar doğrulandı (tahminle yazılmadı):
 *   - fananimi/pyzk        → zk/base.py, zk/const.py   (referans alınan davranış)
 *   - caobo171/node-zklib  → constants.js, utils.js
 *   - adrobinoga/zk-protocol → protocol.md, sections/*.md (belgelenmiş çerçeve yapısı)
 *   - dnaextrim/php_zklib  → zklib.php (UDP biçimi)
 */
final class ZkProtocol
{
    // --- oturum / veri akışı ------------------------------------------------
    public const CMD_CONNECT = 1000;

    public const CMD_EXIT = 1001;

    public const CMD_ENABLEDEVICE = 1002;

    public const CMD_DISABLEDEVICE = 1003;

    public const CMD_RESTART = 1004;

    public const CMD_REFRESHDATA = 1013;

    public const CMD_GET_VERSION = 1100;

    public const CMD_AUTH = 1102;

    public const CMD_PREPARE_DATA = 1500;

    public const CMD_DATA = 1501;

    public const CMD_FREE_DATA = 1502;

    public const CMD_DATA_WRRQ = 1503;   // pyzk: _CMD_PREPARE_BUFFER

    public const CMD_DATA_RDY = 1504;    // pyzk: _CMD_READ_BUFFER

    // --- veri / işlem -------------------------------------------------------
    public const CMD_USERTEMP_RRQ = 9;

    public const CMD_OPTIONS_RRQ = 11;   // parametre oku ("~SerialNumber\0")

    public const CMD_OPTIONS_WRQ = 12;

    public const CMD_ATTLOG_RRQ = 13;

    public const CMD_CLEAR_DATA = 14;

    public const CMD_CLEAR_ATTLOG = 15;

    public const CMD_GET_FREE_SIZES = 50;

    public const CMD_STARTVERIFY = 60;

    public const CMD_GET_TIME = 201;

    public const CMD_SET_TIME = 202;

    // --- cevap kodları ------------------------------------------------------
    public const CMD_ACK_OK = 2000;

    public const CMD_ACK_ERROR = 2001;

    public const CMD_ACK_DATA = 2002;

    public const CMD_ACK_RETRY = 2003;

    public const CMD_ACK_REPEAT = 2004;

    public const CMD_ACK_UNAUTH = 2005;

    public const CMD_ACK_UNKNOWN = 65535;

    // --- FCT_* (CMD_DATA_WRRQ yükündeki "fct" alanı) ------------------------
    public const FCT_ATTLOG = 1;

    public const FCT_USER = 5;

    // --- çerçeve sabitleri --------------------------------------------------
    public const TCP_MAGIC_1 = 20560;   // 0x5050

    public const TCP_MAGIC_2 = 32130;   // 0x7D82

    public const USHRT_MAX = 65535;

    public const MAX_CHUNK_TCP = 0xFFC0;       // 65472

    public const MAX_CHUNK_UDP = 16 * 1024;    // 16384

    /**
     * SAĞLAMA (checksum) — pyzk `__create_checksum` ile BİREBİR.
     *
     * 16 bitlik sözcükleri toplar (little-endian), taşmayı 65535 ile katlar, tek kalan baytı
     * ekler ve birler tümleyenini alır.
     *
     * NOT (bilinçli): Cihazın KENDİ ürettiği sağlama bir eksik/fazladır (belgelenmiş örnek
     * paket 0x6A29 verirken bu algoritma 0x6A28 üretir). pyzk/node-zklib/php_zklib gelen
     * paketlerin sağlamasını DOĞRULAMAZ; cihazlar da doğrulamaz. Bu yüzden biz de yalnız
     * giden pakette sağlama üretiriz, gelende doğrulamayız (ZkPacket::parse).
     */
    public static function checksum(string $buffer): int
    {
        $length = strlen($buffer);
        $offset = 0;
        $sum = 0;

        while ($length > 1) {
            $sum += unpack('v', substr($buffer, $offset, 2))[1];
            if ($sum > self::USHRT_MAX) {
                $sum -= self::USHRT_MAX;
            }
            $offset += 2;
            $length -= 2;
        }

        if ($length === 1) {
            $sum += ord($buffer[$offset]);
        }

        while ($sum > self::USHRT_MAX) {
            $sum -= self::USHRT_MAX;
        }

        $sum = ~$sum;
        while ($sum < 0) {
            $sum += self::USHRT_MAX;
        }

        return $sum & 0xFFFF;
    }

    /**
     * 8 baytlık komut başlığı + yük. Sağlama, checksum alanı SIFIRken ve reply_id'nin ESKİ
     * değeriyle hesaplanır; paket ise ARTIRILMIŞ reply_id ile kurulur (pyzk/node-zklib/php_zklib
     * üçü de böyle yapar, cihazlar bunu bekler).
     *
     * @return array{0:string, 1:int} [paket, yeni reply_id]
     */
    public static function buildCommandFrame(int $command, string $payload, int $sessionId, int $replyId): array
    {
        $unchecked = pack('vvvv', $command, 0, $sessionId, $replyId).$payload;
        $checksum = self::checksum($unchecked);

        $nextReplyId = $replyId + 1;
        if ($nextReplyId >= self::USHRT_MAX) {
            $nextReplyId -= self::USHRT_MAX;
        }

        return [pack('vvvv', $command, $checksum, $sessionId, $nextReplyId).$payload, $nextReplyId];
    }

    /** TCP'de komut çerçevesinin başına gelen 8 baytlık taşıyıcı başlık. */
    public static function wrapTcp(string $frame): string
    {
        return pack('vvV', self::TCP_MAGIC_1, self::TCP_MAGIC_2, strlen($frame)).$frame;
    }

    /**
     * TCP taşıyıcı başlığını doğrular ve ardından gelecek bayt sayısını döner.
     *
     * @return int komut başlığı dâhil uzunluk
     */
    public static function readTcpTop(string $top): int
    {
        if (strlen($top) < 8) {
            throw new ZkProtocolException('Cihazdan eksik paket geldi.', 'Cihaz farklı bir protokol konuşuyor olabilir; --aktarim=udp ile deneyin.');
        }

        $parts = unpack('vmagic1/vmagic2/Vlength', $top);

        if ($parts['magic1'] !== self::TCP_MAGIC_1 || $parts['magic2'] !== self::TCP_MAGIC_2) {
            throw new ZkProtocolException(
                'Cihazdan tanınmayan bir yanıt geldi (paket imzası yanlış).',
                'Bu portta başka bir servis çalışıyor olabilir. Cihaz menüsünden portun 4370 olduğunu doğrulayın ya da --aktarim=udp deneyin.',
                ['imza' => sprintf('%04X %04X', $parts['magic1'], $parts['magic2'])],
            );
        }

        if ($parts['length'] < 8 || $parts['length'] > 1024 * 1024) {
            throw new ZkProtocolException('Cihaz geçersiz paket uzunluğu bildirdi.', 'Cihazı yeniden başlatıp tekrar deneyin.', ['uzunluk' => $parts['length']]);
        }

        return $parts['length'];
    }

    /** CMD_DATA_WRRQ (1503) yükü: '<bhii' = 11 bayt. */
    public static function bufferRequest(int $command, int $fct = 0, int $ext = 0): string
    {
        return pack('c', 1).pack('v', $command).pack('V', $fct).pack('V', $ext);
    }

    /** CMD_DATA_RDY (1504) yükü: '<ii' = 8 bayt. */
    public static function chunkRequest(int $start, int $size): string
    {
        return pack('V', $start).pack('V', $size);
    }

    /**
     * İLETİŞİM ŞİFRESİ ANAHTARI (make_commkey) — pyzk base.py:23-57 ile birebir.
     * 32 bitlik ters çevirme + oturum no + 'ZKSO' XOR + yarım takası + ticks XOR.
     */
    public static function commKey(int $key, int $sessionId, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            $k = ($key & (1 << $i)) ? ((($k << 1) | 1) & 0xFFFFFFFF) : (($k << 1) & 0xFFFFFFFF);
        }

        $k = ($k + $sessionId) & 0xFFFFFFFF;
        $c = array_values(unpack('C4', pack('V', $k)));

        $c0 = $c[0] ^ 0x5A;  // 'Z'
        $c1 = $c[1] ^ 0x4B;  // 'K'
        $c2 = $c[2] ^ 0x53;  // 'S'
        $c3 = $c[3] ^ 0x4F;  // 'O'
        unset($c0);          // pyzk'ta da kullanılmaz; 16 bitlik yarımlar takas edilir

        $b = $ticks & 0xFF;

        return chr($c2 ^ $b).chr($c3 ^ $b).chr($b).chr($c1 ^ $b);
    }

    /** ZK zaman kodlaması (zkemsdk.c EncodeTime). */
    public static function encodeTime(CarbonImmutable $time): int
    {
        return ((($time->year % 100) * 12 * 31 + ($time->month - 1) * 31 + $time->day - 1) * 86400)
            + ($time->hour * 60 + $time->minute) * 60 + $time->second;
    }

    /** ZK zaman çözme (zkemsdk.c DecodeTime). Ay = 31 gün varsayar; takvim değil sayaçtır. */
    public static function decodeTime(int $value): CarbonImmutable
    {
        $second = $value % 60;
        $value = intdiv($value, 60);
        $minute = $value % 60;
        $value = intdiv($value, 60);
        $hour = $value % 24;
        $value = intdiv($value, 24);
        $day = $value % 31 + 1;
        $value = intdiv($value, 31);
        $month = $value % 12 + 1;
        $year = intdiv($value, 12) + 2000;

        // Saat dilimi PHP'nin varsayılanıdır (Laravel bunu config('app.timezone') ile kurar).
        // Böylece bu sınıf çerçeveden bağımsız kalır ve sahte cihaz betiğinde de çalışır.
        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second);
    }

    /** 4 baytlık kodlanmış zaman alanını çözer. */
    public static function decodeTimeBytes(string $bytes): CarbonImmutable
    {
        return self::decodeTime(unpack('V', str_pad(substr($bytes, 0, 4), 4, "\0"))[1]);
    }

    /** Cihazdan gelen "~Ad=Değer\0" biçimli parametre yanıtından değeri ayıklar. */
    public static function parseParameter(string $payload): string
    {
        $parts = explode('=', $payload, 2);
        $value = $parts[1] ?? $parts[0];
        $value = explode("\0", $value)[0];

        return trim(str_replace('=', '', $value));
    }

    /** Cevap kodu "başarı" sayılır mı? (pyzk: ACK_OK, PREPARE_DATA ve DATA da olumludur) */
    public static function isSuccess(int $command): bool
    {
        return in_array($command, [self::CMD_ACK_OK, self::CMD_PREPARE_DATA, self::CMD_DATA], true);
    }
}
