<?php

namespace App\Services\Academic\Timetable;

/** Yumuşak kısıt ağırlıkları (ceza puanı çarpanları). 0 = kısıt yok sayılır. */
final class Weights
{
    public const DEFAULTS = [
        'spread' => 10,      // aynı ders aynı gün üst sınırı aşarsa (fazla saat başına)
        'block' => 3,        // aynı gün aynı dersin saatleri ardışık değilse
        'gaps' => 4,         // öğretmenin boş saati (saat başına)
        'balance' => 2,      // öğretmen günlük yük dengesizliği
        'homeroom' => 1,     // sınıf kendi dersliği dışında
        'hard_early' => 2,   // zor ders günün geç diliminde (0–1 konum)
        'consistency' => 8,  // bir sınıfın aynı dersine birden fazla öğretmen
        'target' => 2,       // öğretmenin haftalık yükü hedef saatinden sapıyor (saat başına)
    ];

    public const LABELS = [
        'spread' => 'Derslerin haftaya yayılması',
        'block' => 'Aynı gündeki saatlerin ardışık olması',
        'gaps' => 'Öğretmen boş saati',
        'balance' => 'Öğretmen günlük yük dengesi',
        'homeroom' => 'Sınıfın kendi dersliği',
        'hard_early' => 'Zor dersler erken saatte',
        'consistency' => 'Aynı derse aynı öğretmen',
        'target' => 'Öğretmen hedef saatine yakınlık',
    ];

    /** @return array<string,float> */
    public static function normalize(?array $input): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $v = $input[$key] ?? $default;
            $out[$key] = max(0.0, min(50.0, is_numeric($v) ? (float) $v : (float) $default));
        }

        return $out;
    }
}
