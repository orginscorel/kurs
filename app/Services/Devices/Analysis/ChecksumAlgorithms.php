<?php

namespace App\Services\Devices\Analysis;

/**
 * Yaygın sağlama algoritmaları (standart, belgeli tanımlar). Protokol analizinde ADAY denemek içindir;
 * hiçbir cihaz protokolüne "bu algoritmadır" diye bağlanmaz.
 */
final class ChecksumAlgorithms
{
    /** @return array<string, array{bytes:int, fn:callable(string):string}> ad → [genişlik, hesap (ham bayt çıktısı)] */
    public static function all(): array
    {
        return [
            'Toplam mod 256' => ['bytes' => 1, 'fn' => fn (string $d) => chr(array_sum(self::bytes($d)) & 0xFF)],
            'Toplam mod 65536 (LE)' => ['bytes' => 2, 'fn' => fn (string $d) => pack('v', array_sum(self::bytes($d)) & 0xFFFF)],
            'Toplam mod 65536 (BE)' => ['bytes' => 2, 'fn' => fn (string $d) => pack('n', array_sum(self::bytes($d)) & 0xFFFF)],
            'XOR (8 bit)' => ['bytes' => 1, 'fn' => fn (string $d) => chr(array_reduce(self::bytes($d), fn ($c, $b) => $c ^ $b, 0))],
            'CRC16-CCITT FFFF (BE)' => ['bytes' => 2, 'fn' => fn (string $d) => pack('n', self::crc16Ccitt($d))],
            'CRC16-CCITT FFFF (LE)' => ['bytes' => 2, 'fn' => fn (string $d) => pack('v', self::crc16Ccitt($d))],
            'CRC16-MODBUS (LE)' => ['bytes' => 2, 'fn' => fn (string $d) => pack('v', self::crc16Modbus($d))],
            'CRC16-MODBUS (BE)' => ['bytes' => 2, 'fn' => fn (string $d) => pack('n', self::crc16Modbus($d))],
            'CRC32 (LE)' => ['bytes' => 4, 'fn' => fn (string $d) => pack('V', crc32($d))],
            'CRC32 (BE)' => ['bytes' => 4, 'fn' => fn (string $d) => pack('N', crc32($d))],
        ];
    }

    /** @return list<int> */
    private static function bytes(string $d): array
    {
        return $d === '' ? [] : array_values(unpack('C*', $d));
    }

    public static function crc16Ccitt(string $data): int
    {
        $crc = 0xFFFF;
        foreach (self::bytes($data) as $b) {
            $crc ^= $b << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }

    public static function crc16Modbus(string $data): int
    {
        $crc = 0xFFFF;
        foreach (self::bytes($data) as $b) {
            $crc ^= $b;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 1) ? ($crc >> 1) ^ 0xA001 : $crc >> 1;
            }
        }

        return $crc;
    }
}
