<?php

namespace App\Services\Devices\Network;


/**
 * HAM TCP TANILAMASI (geliştirici modu) — protokol bilinmeyen cihazda "kablonun ucunda ne var?" sorusu.
 *
 * Varsayılan: HİÇBİR ŞEY GÖNDERMEZ, yalnız dinler (bazı cihazlar bağlantı açılınca karşılama paketi yollar).
 * İsteğe bağlı: kullanıcının yazdığı HEX baytlar gönderilir — uygulama kendisi paket UYDURMAZ.
 * Kaydedilen: bağlantı başlangıcı, bağlanma süresi, yerel/uzak uçlar, gönderilen/alınan bayt, kapanış
 * nedeni, HEX dökümü ve yazdırılabilir ASCII önizleme.
 */
class RawTcpDiagnostic
{
    public const MAX_READ_BYTES = 65536;

    public function __construct(private readonly TcpSession $session) {}

    /**
     * Tek paketlik (ya da yalnız dinleme) oturum — TcpSession üzerinden; RAW log'a da düşer.
     *
     * @param  string|null  $sendHex  gönderilecek baytlar (HEX; boşluk/iki nokta/virgül serbest). null/'' = gönderme
     */
    public function run(string $host, int $port, ?string $sendHex = null, float $listenSeconds = 5.0, float $connectTimeout = 3.0, ?int $deviceId = null, string $tag = 'TERMINAL'): array
    {
        $listenSeconds = min(max($listenSeconds, 0.5), 30.0);
        $send = self::parseHex($sendHex);

        if ($send === false) {
            return ['durum' => 'hata', 'mesaj' => 'Gönderilecek HEX geçersiz. Yalnız 0-9 A-F ve boşluk/virgül kullanın; her bayt iki hane olmalı (ör. "50 00 0A FF").'];
        }

        $startedAt = now()->toIso8601String();
        $r = $send === ''
            ? $this->session->run($host, $port, [], $listenSeconds, $listenSeconds, $deviceId, $tag, $connectTimeout)
            : $this->session->run($host, $port, [$send], $listenSeconds, 0.0, $deviceId, $tag, $connectTimeout);

        $received = (string) hex2bin($r['alinan_ham_hex']);
        $close = match ($r['kapanis']) {
            'baglanti_kurulamadi' => 'baglanti_kurulamadi',
            'cihaz_kapatti' => 'cihaz_kapatti',
            'baglanti_sifirlandi' => 'baglanti_sifirlandi',
            default => 'dinleme_suresi_doldu',
        };

        return [
            'durum' => $r['durum'],
            'oturum' => $r['oturum'],
            'baslangic' => $startedAt,
            'hedef' => $r['hedef'],
            'cozumleme' => filter_var($host, FILTER_VALIDATE_IP) ? 'IP adresi (çözümleme gerekmedi)' : 'Ad çözümlendi',
            'soket' => $r['soket'],
            'gonderilen_bayt' => $r['tx_bayt'],
            'gonderilen_hex' => $send !== '' ? self::hexDump($send) : null,
            'alinan_bayt' => $r['rx_bayt'],
            'dinleme_sn' => $listenSeconds,
            'kapanis' => $close,
            'kapanis_metni' => self::closeText($close, $r['rx_bayt']),
            'hex' => self::hexDump($received),
            'ascii' => self::ascii($received),
            'zaman_cizelgesi' => array_map(fn ($s) => ['t_ms' => $s['t_ms'], 'olay' => $s['durum'].($s['not'] ? ' — '.$s['not'] : '')], $r['durumlar']),
            'sure_ms' => $r['sure_ms'],
        ];
    }

    /** @return string|false  ham baytlar ('' = gönderme), geçersizse false */
    public static function parseHex(?string $hex): string|false
    {
        $clean = preg_replace('/0x|[\s:,;-]/i', '', (string) $hex) ?? '';

        if ($clean === '') {
            return '';
        }

        if (! ctype_xdigit($clean) || strlen($clean) % 2 !== 0 || strlen($clean) > 8192) {
            return false;
        }

        return (string) hex2bin($clean);
    }

    /** Klasik 16 bayt/satır HEX dökümü (ofset + hex + ascii). */
    public static function hexDump(string $bytes): string
    {
        $lines = [];

        foreach (str_split($bytes, 16) as $i => $row) {
            if ($row === '') {
                continue;
            }
            $hex = implode(' ', str_split(bin2hex($row), 2));
            $lines[] = sprintf('%08x  %-47s  |%s|', $i * 16, $hex, self::ascii($row));
        }

        return implode("\n", $lines);
    }

    /** Yazdırılabilir ASCII önizleme (diğer baytlar nokta). */
    public static function ascii(string $bytes): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '.', $bytes);
    }

    private static function closeText(string $reason, int $received): string
    {
        return match ($reason) {
            'baglanti_kurulamadi' => 'Soket açılamadı.',
            'cihaz_kapatti' => "Cihaz bağlantıyı kapattı ({$received} bayt alındıktan sonra).",
            'baglanti_sifirlandi' => "Cihaz bağlantıyı sıfırladı (RST) ({$received} bayt alındıktan sonra).",
            default => $received > 0 ? "Dinleme süresi doldu; {$received} bayt alındı." : 'Dinleme süresi doldu; cihaz hiçbir şey göndermedi (bu cihazlar genelde önce istemcinin konuşmasını bekler).',
        };
    }
}
