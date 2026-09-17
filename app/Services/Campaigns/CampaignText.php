<?php

namespace App\Services\Campaigns;

/**
 * Kişiselleştirme değişkenleri: {ad} ya da {{ad}} yazımı kabul edilir.
 * Eski şablon adları da çalışır: {{ogrenci_adi}} → {ogrenci_ad}, {{veli_adi}} → {ad_soyad}.
 */
final class CampaignText
{
    public const VARIABLES = [
        'ad' => 'Alıcının adı',
        'soyad' => 'Alıcının soyadı',
        'ad_soyad' => 'Alıcının adı soyadı',
        'ogrenci_ad' => 'Öğrencinin adı soyadı (veli için çocuk(lar)ı)',
        'sinif' => 'Öğrencinin sınıfı',
        'kurum' => 'Kurum adı',
        'kurum_tel' => 'Kurum telefonu',
    ];

    public const ALIASES = ['ogrenci_adi' => 'ogrenci_ad', 'veli_adi' => 'ad_soyad', 'adi' => 'ad', 'adsoyad' => 'ad_soyad'];

    private const PATTERN = '/\{\{?\s*([a-zA-Z_ğüşıöçĞÜŞİÖÇ]+)\s*\}?\}/u';

    /** @param array<string, string> $vars */
    public static function render(?string $text, array $vars): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return (string) preg_replace_callback(self::PATTERN, function ($m) use ($vars) {
            $key = self::canonical($m[1]);
            if (! array_key_exists($key, self::VARIABLES)) {
                return $m[0];
            }

            return (string) ($vars[$key] ?? '');
        }, $text);
    }

    /** @return list<string> metinde geçen ama tanımlı olmayan değişkenler */
    public static function unknown(?string $text): array
    {
        if (! $text) {
            return [];
        }
        preg_match_all(self::PATTERN, $text, $m);

        return array_values(array_unique(array_filter($m[1], fn ($k) => ! array_key_exists(self::canonical($k), self::VARIABLES))));
    }

    public static function canonical(string $key): string
    {
        $key = mb_strtolower($key);

        return self::ALIASES[$key] ?? $key;
    }

    /** @return array<string, string> */
    public static function varsFor(array $person, array $institution): array
    {
        return array_merge([
            'ad' => $person['first_name'] ?? '',
            'soyad' => $person['last_name'] ?? '',
            'ad_soyad' => $person['name'] ?? '',
            'ogrenci_ad' => '',
            'sinif' => '',
            'kurum' => (string) ($institution['name'] ?? ''),
            'kurum_tel' => (string) ($institution['phone'] ?? ''),
        ], array_map('strval', $person['vars'] ?? []));
    }

    /**
     * Ticari SMS'e (ya da istenirse duyuruya) eklenen ret metni. Örnek: "SMS almamak için RET yazıp 4609'a gönderin. B001"
     * HUKUKEN TEYİT EDİLMELİ: metin biçimi, MERSİS/İYS numarası zorunluluğu.
     */
    public static function smsOptOut(array $smsConfig): ?string
    {
        $number = trim((string) ($smsConfig['ret_number'] ?? ''));
        if ($number === '') {
            return null;
        }
        $template = trim((string) ($smsConfig['opt_out_text'] ?? '')) ?: 'SMS almamak için RET yazıp {ret_no} numarasına ücretsiz gönderin.';
        $text = str_replace('{ret_no}', $number, $template);
        if ($code = trim((string) ($smsConfig['ret_code'] ?? ''))) {
            $text .= ' '.$code;
        }
        if ($mersis = trim((string) ($smsConfig['mersis_no'] ?? ''))) {
            $text .= ' Mersis: '.$mersis;
        }

        return $text;
    }
}
