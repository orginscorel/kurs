<?php

namespace App\Services\Imports;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Excel hücre değerlerini normalleştiren saf yardımcılar (DB'ye dokunmaz; birim testli).
 * Geçersiz değerde `false` döner; boş hücrede `null`.
 */
final class ImportValue
{
    /** Türkçe duyarlı küçük harf + ASCII katlama: "Öğrenci No*" → "ogrenci no" */
    public static function fold(?string $value): string
    {
        $v = str_replace(['İ', 'I'], ['i', 'ı'], (string) $value);
        $v = mb_strtolower($v, 'UTF-8');
        $v = strtr($v, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
        $v = preg_replace('/\(.*?\)/u', ' ', $v);
        $v = preg_replace('/[^a-z0-9]+/', ' ', $v);

        return trim(preg_replace('/\s+/', ' ', $v));
    }

    /** Hücreyi metne çevirir (tam sayı olan ondalıklar bilimsel gösterime düşmez). */
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('d.m.Y');
        }
        if (is_bool($value)) {
            return $value ? 'Evet' : 'Hayır';
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = sprintf('%.0f', $value);
        }
        $s = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $s === '' ? null : $s;
    }

    /** Ad/soyad: fazla boşluk temizlenir, tamamı büyük/küçük yazılmışsa Türkçe baş harf büyütülür. */
    public static function name(mixed $value): ?string
    {
        $s = self::text($value);
        if ($s === null) {
            return null;
        }
        $upper = mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $s), 'UTF-8');
        $lower = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $s), 'UTF-8');
        if ($s !== $upper && $s !== $lower) {
            return $s;
        }

        return implode(' ', array_map(function (string $w) {
            $w = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $w), 'UTF-8');
            $first = mb_substr($w, 0, 1);
            $first = $first === 'i' ? 'İ' : mb_strtoupper($first, 'UTF-8');

            return $first.mb_substr($w, 1);
        }, explode(' ', $s)));
    }

    /** @return string|null|false  Y-m-d */
    public static function date(mixed $value): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{5}(\.0+)?$/', trim($value)))) {
            $serial = (int) $value;
            if ($serial < 1 || $serial > 80000) {
                return false;
            }

            // Excel seri tarihi (1900 sistemi): 25569 = 1970-01-01
            return CarbonImmutable::createFromTimestampUTC(($serial - 25569) * 86400)->format('Y-m-d');
        }
        $s = trim((string) $value);
        foreach (['/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/' => [3, 2, 1], '/^(\d{4})-(\d{1,2})-(\d{1,2})$/' => [1, 2, 3]] as $re => [$y, $m, $d]) {
            if (preg_match($re, $s, $mm) && checkdate((int) $mm[$m], (int) $mm[$d], (int) $mm[$y])) {
                return sprintf('%04d-%02d-%02d', $mm[$y], $mm[$m], $mm[$d]);
            }
        }

        return false;
    }

    /** @return bool|null  null = boş, geçersizse null değil false değil: bilinmeyen metin null döner */
    public static function bool(mixed $value, ?bool $default = null): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $f = self::fold(self::text($value));
        if ($f === '') {
            return $default;
        }
        if (in_array($f, ['evet', 'e', 'var', 'x', '1', 'true', 'yes', 'acik', 'olsun'], true)) {
            return true;
        }
        if (in_array($f, ['hayir', 'h', 'yok', '0', 'false', 'no', 'kapali', 'olmasin'], true)) {
            return false;
        }

        return $default;
    }

    /** @return string|null|false */
    public static function nationalId(mixed $value): string|null|false
    {
        $s = self::text($value);
        if ($s === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $s);
        if ($digits !== preg_replace('/\s/', '', $s) || strlen($digits) !== 11) {
            return false;
        }

        return \App\Support\Sensitive::isValidNationalId($digits) ? $digits : false;
    }

    /**
     * Türkiye telefonu → yerel 11 hane ("05321112233"). Yurt dışı (+ ile başlayan, 90 dışı) numara korunur.
     *
     * @return string|null|false
     */
    public static function phone(mixed $value): string|null|false
    {
        $s = self::text($value);
        if ($s === null) {
            return null;
        }
        if (preg_match('/[a-zçğıöşü]/iu', $s)) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $s);
        if (str_starts_with($digits, '0090')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10 && preg_match('/^[2-5]/', $digits)) {
            return '0'.$digits;
        }
        if (str_starts_with(trim($s), '+') && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+'.$digits;
        }

        return false;
    }

    public static function isMobile(?string $phone): bool
    {
        return $phone !== null && (bool) preg_match('/^05\d{9}$/', $phone);
    }

    /** @return string|null|false */
    public static function email(mixed $value): string|null|false
    {
        $s = self::text($value);
        if ($s === null) {
            return null;
        }
        $s = mb_strtolower($s);

        return filter_var($s, FILTER_VALIDATE_EMAIL) && strlen($s) <= 190 ? $s : false;
    }

    /**
     * Metni seçenek sözlüğünde arar (katlanmış anahtarla).
     *
     * @param  array<string, list<string>>  $choices  hedef değer => kabul edilen yazımlar
     * @return string|null|false
     */
    public static function choice(mixed $value, array $choices): string|null|false
    {
        $f = self::fold(self::text($value));
        if ($f === '') {
            return null;
        }
        foreach ($choices as $target => $aliases) {
            foreach ([$target, ...$aliases] as $alias) {
                if ($f === self::fold($alias)) {
                    return $target;
                }
            }
        }

        return false;
    }

    public const GENDERS = ['female' => ['kız', 'kiz', 'k', 'kadın', 'bayan', 'kadin'], 'male' => ['erkek', 'e', 'bay'], 'other' => ['diğer']];

    public const RELATIONSHIPS = ['mother' => ['anne', 'annesi'], 'father' => ['baba', 'babası'], 'guardian' => ['vasi', 'vasisi'], 'other' => ['diğer', 'akraba', 'abla', 'abi', 'ağabey', 'teyze', 'dayı', 'amca', 'hala', 'dede', 'nine', 'babaanne', 'anneanne', 'kardeş'], 'parent' => ['veli']];

    public const FIELDS = ['SAY' => ['sayısal', 'sayisal'], 'EA' => ['eşit ağırlık', 'esit agirlik', 'eşit'], 'SOZ' => ['söz', 'sözel', 'sozel'], 'DIL' => ['dil', 'yabancı dil', 'ydt'], 'TYT' => ['tyt'], 'LGS' => ['lgs']];

    public const EMPLOYMENT = ['full_time' => ['tam zamanlı', 'tam', 'kadrolu', 'tam gün'], 'part_time' => ['yarı zamanlı', 'yarı', 'yari', 'yarım gün', 'part time'], 'hourly' => ['saatlik', 'ek ders', 'saat ücretli', 'ders ücretli']];
}
