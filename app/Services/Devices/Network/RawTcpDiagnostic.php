<?php

namespace App\Services\Devices\Network;

use Illuminate\Support\Facades\Log;

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

    public function __construct(private readonly TcpProbe $probe) {}

    /**
     * @param  string|null  $sendHex  gönderilecek baytlar (HEX; boşluk/iki nokta serbest). null/'' = gönderme
     */
    public function run(string $host, int $port, ?string $sendHex = null, float $listenSeconds = 5.0, float $connectTimeout = 3.0): array
    {
        $listenSeconds = min(max($listenSeconds, 0.5), 30.0);
        $startedAt = now();
        $timeline = [['t_ms' => 0, 'olay' => "Bağlantı başlatıldı → {$host}:{$port}/tcp"]];
        $t0 = hrtime(true);
        $at = fn () => (int) round((hrtime(true) - $t0) / 1_000_000);

        $send = self::parseHex($sendHex);
        if ($send === false) {
            return ['durum' => 'hata', 'mesaj' => 'Gönderilecek HEX geçersiz. Yalnız 0-9 A-F ve boşluk kullanın (ör. "50 00 00 00").'];
        }

        [$stream, $socket] = $this->probe->open($host, $port, $connectTimeout);
        $timeline[] = ['t_ms' => $at(), 'olay' => $socket->connected ? "Bağlandı ({$socket->durationMs} ms), yerel uç {$socket->localIp}:{$socket->localPort}" : 'Bağlanamadı: '.$socket->message];

        $received = '';
        $sent = 0;
        $closeReason = 'baglanti_kurulamadi';

        if ($stream !== null) {
            stream_set_blocking($stream, false);

            if ($send !== '') {
                $sent = (int) @fwrite($stream, $send);
                $timeline[] = ['t_ms' => $at(), 'olay' => "{$sent} bayt gönderildi"];
            } else {
                $timeline[] = ['t_ms' => $at(), 'olay' => 'Hiçbir şey gönderilmedi; yalnız dinleniyor'];
            }

            $deadline = microtime(true) + $listenSeconds;
            $closeReason = 'dinleme_suresi_doldu';

            while (microtime(true) < $deadline && strlen($received) < self::MAX_READ_BYTES) {
                $read = [$stream];
                $write = $except = null;
                $left = max(0.0, $deadline - microtime(true));
                $ready = @stream_select($read, $write, $except, (int) $left, (int) (($left - floor($left)) * 1_000_000));

                if ($ready === false) {
                    $closeReason = 'okuma_hatasi';
                    break;
                }
                if ($ready === 0) {
                    continue;
                }

                $chunk = @fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        $closeReason = 'cihaz_kapatti';
                        $timeline[] = ['t_ms' => $at(), 'olay' => 'Cihaz bağlantıyı kapattı'];
                        break;
                    }

                    continue;
                }

                $received .= $chunk;
                $timeline[] = ['t_ms' => $at(), 'olay' => strlen($chunk).' bayt alındı'];
            }

            if (strlen($received) >= self::MAX_READ_BYTES) {
                $closeReason = 'bayt_siniri';
            }

            @fclose($stream);
            $timeline[] = ['t_ms' => $at(), 'olay' => 'Bağlantı kapatıldı'];
        }

        $result = [
            'durum' => $socket->connected ? 'ok' : 'hata',
            'baslangic' => $startedAt->toIso8601String(),
            'hedef' => "{$host}:{$port}",
            'cozumleme' => filter_var($host, FILTER_VALIDATE_IP) ? 'IP adresi (çözümleme gerekmedi)' : 'Ad çözümlendi',
            'soket' => $socket->toArray(),
            'gonderilen_bayt' => $sent,
            'gonderilen_hex' => $send !== '' ? self::hexDump($send) : null,
            'alinan_bayt' => strlen($received),
            'dinleme_sn' => $listenSeconds,
            'kapanis' => $closeReason,
            'kapanis_metni' => self::closeText($closeReason, strlen($received)),
            'hex' => self::hexDump($received),
            'ascii' => self::ascii($received),
            'zaman_cizelgesi' => $timeline,
            'sure_ms' => $at(),
        ];

        Log::channel('terminal')->info('Ham TCP tanılaması', array_intersect_key($result, array_flip(['hedef', 'durum', 'gonderilen_bayt', 'alinan_bayt', 'kapanis', 'sure_ms'])));

        return $result;
    }

    /** @return string|false  ham baytlar ('' = gönderme), geçersizse false */
    public static function parseHex(?string $hex): string|false
    {
        $clean = preg_replace('/[\s:,-]|0x/i', '', (string) $hex) ?? '';

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
            'bayt_siniri' => 'Okuma sınırına (64 KB) ulaşıldı.',
            'okuma_hatasi' => 'Okuma sırasında hata oluştu.',
            default => $received > 0 ? "Dinleme süresi doldu; {$received} bayt alındı." : 'Dinleme süresi doldu; cihaz hiçbir şey göndermedi (bu cihazlar genelde önce istemcinin konuşmasını bekler).',
        };
    }
}
