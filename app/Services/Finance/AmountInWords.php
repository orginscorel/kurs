<?php

namespace App\Services\Finance;

use App\Support\Money;

/**
 * Tutarın Türkçe yazıyla ifadesi (makbuz ve sözleşme için).
 * "12345.67" → "On iki bin üç yüz kırk beş Türk lirası altmış yedi kuruş"
 * Kurallar: "bir yüz" / "bir bin" denmez; kuruş yoksa kuruş kısmı yazılmaz.
 */
final class AmountInWords
{
    private const ONES = ['', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz'];

    private const TENS = ['', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan'];

    private const SCALES = ['', 'bin', 'milyon', 'milyar', 'trilyon'];

    public static function lira(string|int|float $amount): string
    {
        $value = Money::of($amount);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$int, $dec] = explode('.', $value);

        $words = self::integer($int);
        $text = ($words === '' ? 'sıfır' : $words).' Türk lirası';

        if ((int) $dec > 0) {
            $text .= ' '.self::integer($dec).' kuruş';
        }

        if ($negative) {
            $text = 'eksi '.$text;
        }

        // Türkçe büyük harf: i → İ, ı → I
        $first = mb_substr($text, 0, 1);
        $first = ['i' => 'İ', 'ı' => 'I'][$first] ?? mb_strtoupper($first);

        return $first.mb_substr($text, 1);
    }

    /** Tam sayıyı (string, keyfi uzunlukta) Türkçe kelimelere çevirir. 0 → "" */
    public static function integer(string $digits): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '';
        }

        $groups = array_reverse(str_split(str_pad($digits, (int) ceil(strlen($digits) / 3) * 3, '0', STR_PAD_LEFT), 3));
        if (count($groups) > count(self::SCALES)) {
            throw new \InvalidArgumentException('Tutar yazıya çevrilemeyecek kadar büyük.');
        }

        $parts = [];
        foreach ($groups as $scale => $group) {
            $n = (int) $group;
            if ($n === 0) {
                continue;
            }
            // "bin" için "bir bin" denmez.
            $chunk = ($scale === 1 && $n === 1) ? '' : self::hundreds($n);
            $parts[] = trim($chunk.' '.self::SCALES[$scale]);
        }

        return implode(' ', array_reverse($parts));
    }

    private static function hundreds(int $n): string
    {
        $h = intdiv($n, 100);
        $t = intdiv($n % 100, 10);
        $o = $n % 10;

        $words = [];
        if ($h > 0) {
            $words[] = $h === 1 ? 'yüz' : self::ONES[$h].' yüz';
        }
        if ($t > 0) {
            $words[] = self::TENS[$t];
        }
        if ($o > 0) {
            $words[] = self::ONES[$o];
        }

        return implode(' ', $words);
    }
}
