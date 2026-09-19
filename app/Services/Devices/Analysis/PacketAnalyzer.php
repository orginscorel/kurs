<?php

namespace App\Services\Devices\Analysis;

/**
 * PAKET KARŞILAŞTIRMA — birden çok paketi bayt bayt hizalar, sabit/değişen baytları işaretler ve OLASI alan
 * önerileri üretir (sabit başlık, komut, cihaz kimliği, uzunluk alanı, sağlama toplamı, sabit son ek).
 *
 * Otomatik analiz TAHMİNDİR; sonuçlar hiçbir sürücüye otomatik aktarılmaz. Öneriler ancak birden çok gerçek
 * yakalamayla doğrulanıp elle koda yazılır.
 */
class PacketAnalyzer
{
    public const WARNING = 'Otomatik analiz tahmindir; doğrulanmadan sürücüye eklenmez.';

    /**
     * @param  list<string>  $packets  ham baytlar
     */
    public function compare(array $packets, ?int $machineId = null): array
    {
        $packets = array_values(array_filter($packets, fn ($p) => $p !== ''));
        $n = count($packets);

        if ($n < 2) {
            return ['uyari' => self::WARNING, 'hata' => 'Karşılaştırma için en az iki boş olmayan paket gerekir.'];
        }

        $lengths = array_map('strlen', $packets);
        $maxLen = max($lengths);
        $minLen = min($lengths);
        $columns = [];

        for ($i = 0; $i < $maxLen; $i++) {
            $values = array_map(fn ($p) => $i < strlen($p) ? sprintf('%02x', ord($p[$i])) : null, $packets);
            $present = array_filter($values, fn ($v) => $v !== null);
            $columns[] = [
                'ofset' => $i,
                'degerler' => $values,
                'sabit' => count($present) === $n && count(array_unique($present)) === 1,
            ];
        }

        // Sondan hizalı sabit son ek (farklı boylarda da)
        $suffix = 0;
        while ($suffix < $minLen) {
            $tail = array_map(fn ($p) => $p[strlen($p) - 1 - $suffix], $packets);
            if (count(array_unique($tail)) !== 1) {
                break;
            }
            $suffix++;
        }

        $header = 0;
        while ($header < $minLen && $columns[$header]['sabit']) {
            $header++;
        }

        $suggestions = [];

        if ($header > 0) {
            $suggestions[] = ['alan' => 'Sabit başlık', 'ofset' => 0, 'uzunluk' => $header, 'aciklama' => "İlk {$header} bayt tüm paketlerde aynı: ".$this->hexAt($packets[0], 0, $header)];
        }

        if ($header < $minLen) {
            $suggestions[] = ['alan' => 'Komut (aday)', 'ofset' => $header, 'uzunluk' => 1, 'aciklama' => 'Başlıktan sonraki ilk değişen bayt; farklı işlemlerde değişiyorsa komut kodu olabilir.'];
        }

        foreach ($this->lengthCandidates($packets) as $c) {
            $suggestions[] = $c;
        }

        if ($machineId !== null) {
            foreach ($this->valueCandidates($packets, $machineId) as $c) {
                $suggestions[] = ['alan' => 'Cihaz kimliği (aday)', 'ofset' => $c['ofset'], 'uzunluk' => $c['uzunluk'], 'aciklama' => "Tüm paketlerde Machine ID ({$machineId}) değerini {$c['bicim']} olarak taşıyor."];
            }
        }

        foreach ($this->sequenceCandidates($packets, $header) as $c) {
            $suggestions[] = $c;
        }

        foreach ($this->checksumCandidates($packets, $suffix) as $c) {
            $suggestions[] = $c;
        }

        if ($suffix > 0) {
            $suggestions[] = ['alan' => 'Sabit son ek', 'ofset' => -$suffix, 'uzunluk' => $suffix, 'aciklama' => "Son {$suffix} bayt tüm paketlerde aynı: ".$this->hexAt($packets[0], strlen($packets[0]) - $suffix, $suffix)];
        }

        if ($maxLen > $header + $suffix) {
            $suggestions[] = ['alan' => 'Yük (veri)', 'ofset' => $header, 'uzunluk' => null, 'aciklama' => 'Başlık/uzunluk/sağlama dışındaki değişen bölüm.'];
        }

        return [
            'uyari' => self::WARNING,
            'paket_sayisi' => $n,
            'boylar' => $lengths,
            'sutunlar' => $columns,
            'sabit_baslik' => $header,
            'sabit_son_ek' => $suffix,
            'oneriler' => $suggestions,
        ];
    }

    /** Değeri paket boyuyla (sabit farkla) tutarlı eşleşen 1/2/4 baytlık alanlar. Boylar farklı değilse belirsiz. */
    private function lengthCandidates(array $packets): array
    {
        $lengths = array_map('strlen', $packets);
        $distinct = count(array_unique($lengths)) > 1;
        $min = min($lengths);
        $out = [];

        foreach ([[1, 'C', '8 bit'], [2, 'v', '16 bit LE'], [2, 'n', '16 bit BE'], [4, 'V', '32 bit LE'], [4, 'N', '32 bit BE']] as [$w, $fmt, $label]) {
            for ($o = 0; $o + $w <= min($min, 16); $o++) {
                $diffs = [];
                foreach ($packets as $p) {
                    $v = unpack($fmt, substr($p, $o, $w))[1];
                    $diffs[] = strlen($p) - $v;
                }
                if (count(array_unique($diffs)) === 1 && $diffs[0] >= 0 && $diffs[0] <= 32) {
                    $out[] = [
                        'alan' => 'Veri uzunluğu (aday)', 'ofset' => $o, 'uzunluk' => $w,
                        'aciklama' => "{$label}: değer = paket boyu − {$diffs[0]}".($distinct ? '' : ' (UYARI: tüm paketler aynı boyda; farklı boyda paketlerle doğrulayın)'),
                    ];
                }
            }
        }

        return array_slice($out, 0, 6);
    }

    private function valueCandidates(array $packets, int $value): array
    {
        $min = min(array_map('strlen', $packets));
        $out = [];

        foreach ([[1, 'C', '8 bit'], [2, 'v', '16 bit LE'], [2, 'n', '16 bit BE'], [4, 'V', '32 bit LE']] as [$w, $fmt, $label]) {
            for ($o = 0; $o + $w <= $min; $o++) {
                $all = true;
                foreach ($packets as $p) {
                    if (unpack($fmt, substr($p, $o, $w))[1] !== $value) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $out[] = ['ofset' => $o, 'uzunluk' => $w, 'bicim' => $label];
                }
            }
        }

        return array_slice($out, 0, 4);
    }

    /** Paket sırasıyla 1'er artan bayt(lar) → sıra numarası adayı. */
    private function sequenceCandidates(array $packets, int $from): array
    {
        if (count($packets) < 3) {
            return [];
        }

        $min = min(array_map('strlen', $packets));
        $out = [];

        foreach ([[1, 'C', '8 bit'], [2, 'v', '16 bit LE'], [2, 'n', '16 bit BE']] as [$w, $fmt, $label]) {
            for ($o = $from; $o + $w <= $min; $o++) {
                $vals = array_map(fn ($p) => unpack($fmt, substr($p, $o, $w))[1], $packets);
                $ok = true;
                for ($i = 1; $i < count($vals); $i++) {
                    if ($vals[$i] !== $vals[$i - 1] + 1) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $out[] = ['alan' => 'Sıra numarası (aday)', 'ofset' => $o, 'uzunluk' => $w, 'aciklama' => "{$label}: paketten pakete 1 artıyor."];
                }
            }
        }

        return array_slice($out, 0, 3);
    }

    /** Sondaki (sabit son ekten önceki) 1/2/4 bayt, yaygın algoritmalardan biriyle TÜM paketlerde tutuyor mu? */
    private function checksumCandidates(array $packets, int $suffix): array
    {
        $out = [];

        foreach (ChecksumAlgorithms::all() as $name => $algo) {
            $w = $algo['bytes'];
            foreach ([0, $suffix] as $skipTail) {
                foreach ([0, 1, 2, 4] as $start) {
                    $ok = true;
                    foreach ($packets as $p) {
                        $end = strlen($p) - $skipTail - $w;
                        if ($end <= $start) {
                            $ok = false;
                            break;
                        }
                        if (($algo['fn'])(substr($p, $start, $end - $start)) !== substr($p, $end, $w)) {
                            $ok = false;
                            break;
                        }
                    }
                    if ($ok) {
                        $out[] = ['alan' => 'Sağlama toplamı (aday)', 'ofset' => -($skipTail + $w), 'uzunluk' => $w,
                            'aciklama' => "{$name}: bayt {$start}'den sağlamaya kadar olan bölüm üzerinden tüm paketlerde tutuyor".($skipTail ? " (son {$skipTail} bayt son ek hariç)" : '').'.'];
                        break 2;
                    }
                }
            }
        }

        return $out;
    }

    private function hexAt(string $p, int $offset, int $len): string
    {
        return implode(' ', str_split(bin2hex(substr($p, $offset, $len)), 2));
    }
}
