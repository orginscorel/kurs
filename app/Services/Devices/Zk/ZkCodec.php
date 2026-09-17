<?php

namespace App\Services\Devices\Zk;

/**
 * VERİ ÇÖZÜCÜ — cihazdan gelen ham bayt kümelerini kayda çevirir. Ağ bilmez, saf fonksiyondur.
 *
 * Hem "yeni" hem "eski" biçimler desteklenir; biçim, toplam boyutun kayıt sayısına
 * bölünmesiyle bulunur (pyzk `get_attendance` / `get_users` ile aynı yöntem):
 *   yoklama : 40 bayt (yeni/TFT), 16 bayt (eski), 8 bayt (en eski)
 *   kullanıcı: 72 bayt (yeni/ZK8), 28 bayt (eski/ZK6)
 */
final class ZkCodec
{
    /** 40 baytlık akışın başında görülebilen dolgu (pyzk: code_init). */
    private const ATTLOG_SENTINEL = "\xff255\x00\x00\x00\x00\x00";

    /**
     * Yoklama kayıtları.
     *
     * @param  string  $data  read_with_buffer çıktısı (ilk 4 bayt: toplam kayıt baytı)
     * @param  int  $records  cihazın bildirdiği kayıt sayısı (CMD_GET_FREE_SIZES)
     * @return list<ZkAttendanceRecord>
     */
    public static function attendance(string $data, int $records, array $usersByUid = []): array
    {
        if (strlen($data) < 4 || $records <= 0) {
            return [];
        }

        $totalSize = unpack('V', substr($data, 0, 4))[1];
        $recordSize = intdiv($totalSize, $records);
        $body = substr($data, 4);

        // Cihaz sayacı ile gerçek veri uyuşmazsa boyutu veriden tahmin et.
        if (! in_array($recordSize, [8, 16, 40], true)) {
            $recordSize = self::guessRecordSize(strlen($body), [40, 16, 8]);
        }

        return match ($recordSize) {
            8 => self::attendance8($body, $usersByUid),
            16 => self::attendance16($body),
            40 => self::attendance40($body),
            default => [],
        };
    }

    /** @return list<ZkAttendanceRecord> */
    private static function attendance40(string $body): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($body);

        while ($length - $offset >= 40) {
            if (substr($body, $offset, 9) === self::ATTLOG_SENTINEL) {
                $offset += 9;

                continue;
            }

            $rec = substr($body, $offset, 40);
            $offset += 40;

            $uid = unpack('v', substr($rec, 0, 2))[1];
            $userId = self::cstring(substr($rec, 2, 24));
            $verify = ord($rec[26]);
            $time = ZkProtocol::decodeTimeBytes(substr($rec, 27, 4));
            $punch = ord($rec[31]);

            if ($userId === '') {
                continue;
            }

            $out[] = new ZkAttendanceRecord($userId, $time, $punch, $verify, $uid);
        }

        return $out;
    }

    /** @return list<ZkAttendanceRecord> */
    private static function attendance16(string $body): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($body);

        while ($length - $offset >= 16) {
            $rec = substr($body, $offset, 16);
            $offset += 16;

            $userId = unpack('V', substr($rec, 0, 4))[1];
            $time = ZkProtocol::decodeTimeBytes(substr($rec, 4, 4));
            $verify = ord($rec[8]);
            $punch = ord($rec[9]);

            $out[] = new ZkAttendanceRecord((string) $userId, $time, $punch, $verify, $userId);
        }

        return $out;
    }

    /**
     * En eski biçim: kayıtta kullanıcı NUMARASI yok, yalnız cihaz iç kimliği (uid) var.
     * Numara kullanıcı listesinden bulunur (bulunamazsa uid'in kendisi kullanılır).
     *
     * @param  array<int, string>  $usersByUid  uid => kullanıcı numarası
     * @return list<ZkAttendanceRecord>
     */
    private static function attendance8(string $body, array $usersByUid): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($body);

        while ($length - $offset >= 8) {
            $rec = substr($body, $offset, 8);
            $offset += 8;

            $uid = unpack('v', substr($rec, 0, 2))[1];
            $verify = ord($rec[2]);
            $time = ZkProtocol::decodeTimeBytes(substr($rec, 3, 4));
            $punch = ord($rec[7]);

            $out[] = new ZkAttendanceRecord($usersByUid[$uid] ?? (string) $uid, $time, $punch, $verify, $uid);
        }

        return $out;
    }

    /**
     * Kullanıcılar.
     *
     * @param  int  $users  cihazın bildirdiği kullanıcı sayısı
     * @return list<ZkUser>
     */
    public static function users(string $data, int $users): array
    {
        if (strlen($data) < 4 || $users <= 0) {
            return [];
        }

        $totalSize = unpack('V', substr($data, 0, 4))[1];
        $size = intdiv($totalSize, $users);
        $body = substr($data, 4);

        if (! in_array($size, [28, 72], true)) {
            $size = self::guessRecordSize(strlen($body), [72, 28]);
        }

        return match ($size) {
            72 => self::users72($body),
            28 => self::users28($body),
            default => [],
        };
    }

    /** @return list<ZkUser> */
    private static function users72(string $body): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($body);

        while ($length - $offset >= 72) {
            $rec = substr($body, $offset, 72);
            $offset += 72;

            $uid = unpack('v', substr($rec, 0, 2))[1];
            $privilege = ord($rec[2]);
            $password = self::cstring(substr($rec, 3, 8));
            $name = self::text(substr($rec, 11, 24));
            $card = unpack('V', substr($rec, 35, 4))[1];
            $group = ord($rec[39]);
            $userId = self::cstring(substr($rec, 48, 24));

            if ($userId === '' && $uid === 0) {
                continue;
            }

            $out[] = new ZkUser(
                uid: $uid,
                userId: $userId !== '' ? $userId : (string) $uid,
                name: $name !== '' ? $name : 'İsimsiz-'.($userId !== '' ? $userId : $uid),
                privilege: $privilege,
                card: $card > 0 ? (string) $card : '',
                group: $group,
                hasPassword: $password !== '',
            );
        }

        return $out;
    }

    /** @return list<ZkUser> */
    private static function users28(string $body): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($body);

        while ($length - $offset >= 28) {
            $rec = substr($body, $offset, 28);
            $offset += 28;

            $uid = unpack('v', substr($rec, 0, 2))[1];
            $privilege = ord($rec[2]);
            $password = self::cstring(substr($rec, 3, 5));
            $name = self::text(substr($rec, 8, 8));
            $card = unpack('V', substr($rec, 16, 4))[1];
            $group = ord($rec[21]);
            $userId = unpack('V', substr($rec, 24, 4))[1];

            if ($userId === 0 && $uid === 0) {
                continue;
            }

            $out[] = new ZkUser(
                uid: $uid,
                userId: (string) ($userId ?: $uid),
                name: $name !== '' ? $name : 'İsimsiz-'.($userId ?: $uid),
                privilege: $privilege,
                card: $card > 0 ? (string) $card : '',
                group: $group,
                hasPassword: $password !== '',
            );
        }

        return $out;
    }

    /** CMD_GET_FREE_SIZES yanıtı: 4 baytlık int32'ler (pyzk read_sizes ofsetleri). */
    public static function sizes(string $data): array
    {
        $at = function (int $offset) use ($data): ?int {
            return strlen($data) >= $offset + 4 ? unpack('V', substr($data, $offset, 4))[1] : null;
        };

        return [
            'users' => $at(16),
            'fingers' => $at(24),
            'records' => $at(32),
            'users_cap' => $at(60),
            'records_cap' => $at(64),
        ];
    }

    /**
     * Cihazın adları hangi kod sayfasıyla yazdığı kesin değildir (çoğu cihazda GBK ya da
     * Latin-1). Geçerli UTF-8 ise dokunulmaz; değilse Windows-1254 (Türkçe) varsayılır.
     */
    public static function text(string $raw): string
    {
        $value = self::cstring($raw);

        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1254');

        return is_string($converted) ? $converted : '';
    }

    /** Sıfır baytına kadar olan kısım + görünmeyen karakterlerin temizliği. */
    public static function cstring(string $raw): string
    {
        $value = explode("\0", $raw, 2)[0];

        // Not: /u kipi YOK — cihaz adları UTF-8 olmayabilir, /u ile preg_replace null döner.
        return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
    }

    /** Kayıt boyutu cihaz sayacından çıkmadıysa: veriye tam bölünen ilk boyutu seç. */
    private static function guessRecordSize(int $length, array $candidates): int
    {
        foreach ($candidates as $size) {
            if ($length > 0 && $length % $size === 0) {
                return $size;
            }
        }

        return 0;
    }
}
