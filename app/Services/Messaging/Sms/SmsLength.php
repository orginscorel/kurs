<?php

namespace App\Services\Messaging\Sms;

/**
 * SMS karakter / parça hesabı.
 *
 * Kodlama kipleri (SMS entegrasyonu `encoding` ayarı):
 *  - 'tr'      Türkçe karakter desteği (GSM 03.38 + Türkçe dil tablosu). Türk operatörlerinin ve
 *              NetGSM/Mutlucell/VatanSMS'in ilan ettiği sınır: tek parça 155, çok parçada parça başı 150 karakter.
 *              Türkçe tabloda da olmayan bir karakter (emoji, Arapça…) varsa Unicode'a düşer.
 *  - 'unicode' Her zaman UCS-2: tek parça 70, çok parçada 67 karakter.
 *  - 'ascii'   Türkçe harfler Latin karşılığına çevrilir (ş→s, ğ→g, ı→i …) ve GSM-7 ile gönderilir: 160 / 153.
 *              Çevrilemeyen karakter varsa Unicode'a düşer.
 *
 * GSM-7 genişletme karakterleri (^ { } \ [ ~ ] | €) iki karakter yer kaplar.
 */
final class SmsLength
{
    public const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    public const GSM_EXTENDED = "^{}\\[~]|€\f";

    public const TURKISH = 'çÇğĞıİöÖşŞüÜ';

    /** @var array<string, string> */
    public const ASCII_MAP = [
        'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
        'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U',
        'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I', 'û' => 'u', 'Û' => 'U',
        '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...',
    ];

    public const LIMITS = [
        'gsm7' => ['single' => 160, 'multi' => 153],
        'tr' => ['single' => 155, 'multi' => 150],
        'unicode' => ['single' => 70, 'multi' => 67],
    ];

    public const MODES = ['tr', 'unicode', 'ascii'];

    /**
     * @return array{encoding: string, length: int, parts: int, per_part: int, remaining: int, text: string}
     *   encoding: gsm7 | tr | unicode — gerçekte kullanılacak kodlama
     *   length:   hesapta sayılan birim (genişletme karakteri 2 sayılır; Unicode'da UTF-16 birimi)
     *   text:     gönderilecek metin ('ascii' kipinde dönüştürülmüş hâli)
     */
    public static function analyze(string $text, string $mode = 'tr'): array
    {
        $text = str_replace("\r\n", "\n", $text);
        if ($mode === 'ascii') {
            $text = strtr($text, self::ASCII_MAP);
        }

        $encoding = self::detect($text, $mode);
        $length = self::units($text, $encoding);
        $limits = self::LIMITS[$encoding];

        if ($length === 0) {
            return ['encoding' => $encoding, 'length' => 0, 'parts' => 0, 'per_part' => $limits['single'], 'remaining' => $limits['single'], 'text' => $text];
        }

        if ($length <= $limits['single']) {
            $parts = 1;
            $perPart = $limits['single'];
            $remaining = $limits['single'] - $length;
        } else {
            $parts = (int) ceil($length / $limits['multi']);
            $perPart = $limits['multi'];
            $remaining = $parts * $limits['multi'] - $length;
        }

        return ['encoding' => $encoding, 'length' => $length, 'parts' => $parts, 'per_part' => $perPart, 'remaining' => $remaining, 'text' => $text];
    }

    public static function parts(string $text, string $mode = 'tr'): int
    {
        return self::analyze($text, $mode)['parts'];
    }

    private static function detect(string $text, string $mode): string
    {
        if ($mode === 'unicode') {
            return 'unicode';
        }

        $hasTurkish = false;
        foreach (mb_str_split($text) as $char) {
            if (self::isGsm($char)) {
                continue;
            }
            if ($mode === 'tr' && mb_strpos(self::TURKISH, $char) !== false) {
                $hasTurkish = true;

                continue;
            }

            return 'unicode';
        }

        return $hasTurkish ? 'tr' : 'gsm7';
    }

    private static function isGsm(string $char): bool
    {
        return mb_strpos(self::GSM_BASIC, $char) !== false || mb_strpos(self::GSM_EXTENDED, $char) !== false;
    }

    private static function units(string $text, string $encoding): int
    {
        if ($encoding === 'unicode') {
            // UTF-16 kod birimi: BMP dışı karakterler (emoji) 2 birim
            return intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);
        }

        $count = 0;
        foreach (mb_str_split($text) as $char) {
            $count += mb_strpos(self::GSM_EXTENDED, $char) !== false ? 2 : 1;
        }

        return $count;
    }
}
