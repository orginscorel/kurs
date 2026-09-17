<?php

namespace App\Services\Exams\Optical;

/**
 * Kolon eşleştirme (saf). Bir "kaynak" tanımı üç biçimde olabilir:
 *   {"col": 3}                  ayraçlı dosyada 0 tabanlı kolon
 *   {"start": 12, "length": 40} sabit genişlikli satırda 1 tabanlı başlangıç + uzunluk
 *   {"key": "ogrenci_no"}       JSON nesnesinde anahtar
 *   {"fixed": "A"}              sabit değer (ör. tek kitapçıklı sınav)
 *
 * Eşleşme şeması:
 * {
 *   "student_no": kaynak, "name": kaynak|null, "booklet": kaynak|null,
 *   "answers_mode": "combined" | "per_section",
 *   "combined": kaynak,                         tüm cevaplar tek dizide; bölümler sınav sırasına göre kesilir
 *   "sections": {"TUR": kaynak, "MAT": kaynak}  bölüm başına ayrı kaynak
 * }
 */
final class ColumnMapper
{
    /**
     * @param array<int,string>|string|array<string,mixed> $row
     * @param array<string,int> $sectionCounts bölüm kodu => soru sayısı (sınav sırasında)
     * @return array{student_no:?string, name:?string, booklet:?string, answers:array<string,string>}
     */
    public static function map(array|string $row, array $mapping, array $sectionCounts): array
    {
        $answers = [];
        if (($mapping['answers_mode'] ?? 'per_section') === 'combined') {
            $src = $mapping['combined'] ?? null;
            $all = $src ? self::scalar(self::read($row, $src)) : '';
            $offset = 0;
            foreach ($sectionCounts as $code => $count) {
                $answers[$code] = self::padAnswers(mb_substr($all, $offset, $count), $count);
                $offset += $count;
            }
        } else {
            foreach ($sectionCounts as $code => $count) {
                $src = $mapping['sections'][$code] ?? null;
                $answers[$code] = self::padAnswers($src ? self::scalar(self::read($row, $src)) : '', $count);
            }
        }

        return [
            'student_no' => self::clean(self::read($row, $mapping['student_no'] ?? null)),
            'name' => self::clean(self::read($row, $mapping['name'] ?? null)),
            'booklet' => self::clean(self::read($row, $mapping['booklet'] ?? null)),
            'answers' => $answers,
        ];
    }

    /** @param array<int,string>|string|array<string,mixed> $row */
    public static function read(array|string $row, ?array $source): mixed
    {
        if (! $source) {
            return null;
        }
        if (array_key_exists('fixed', $source)) {
            return $source['fixed'];
        }
        if (is_string($row)) {
            if (! isset($source['start'])) {
                return null;
            }
            $start = max(1, (int) $source['start']) - 1;
            $length = isset($source['length']) && (int) $source['length'] > 0 ? (int) $source['length'] : null;

            return $length === null ? mb_substr($row, $start) : mb_substr($row, $start, $length);
        }
        if (isset($source['key'])) {
            $value = $row;
            foreach (explode('.', (string) $source['key']) as $part) {
                if (! is_array($value) || ! array_key_exists($part, $value)) {
                    return null;
                }
                $value = $value[$part];
            }

            return is_array($value) ? null : $value;
        }
        if (isset($source['col'])) {
            return $row[(int) $source['col']] ?? null;
        }

        return null;
    }

    /**
     * Ayraçlı/JSON dosya için sezgisel eşleşme önerisi.
     *
     * @param list<string> $headers
     * @param list<array<int|string,mixed>> $rows örnek satırlar
     * @param array<string,int> $sectionCounts
     * @param array<string,string> $sectionNames bölüm kodu => ad
     */
    public static function guess(array $headers, array $rows, array $sectionCounts, array $sectionNames = [], string $kind = 'delimited'): array
    {
        $src = fn (int|string $i) => $kind === 'json' ? ['key' => (string) $headers[$i]] : ['col' => (int) $i];
        $total = array_sum($sectionCounts);
        $mapping = ['answers_mode' => 'per_section', 'sections' => [], 'student_no' => null, 'name' => null, 'booklet' => null, 'combined' => null];
        $used = [];

        $colValues = function (int $i) use ($rows, $headers, $kind) {
            $out = [];
            foreach (array_slice($rows, 0, 40) as $r) {
                $v = $kind === 'json' ? ($r[$headers[$i]] ?? '') : ($r[$i] ?? '');
                $out[] = is_scalar($v) ? trim((string) $v) : '';
            }

            return $out;
        };

        // 0) JSON: iç içe cevap nesnesi {"answers": {"TUR": "…"}} → bölüm başına "answers.TUR"
        if ($kind === 'json' && $rows !== []) {
            foreach ($headers as $i => $h) {
                $sample = $rows[0][$h] ?? null;
                if (! is_array($sample)) {
                    continue;
                }
                foreach ($sectionCounts as $code => $count) {
                    if (array_key_exists($code, $sample)) {
                        $mapping['sections'][$code] = ['key' => $h.'.'.$code];
                    }
                }
                $used[$i] = true;
            }
        }

        // 1) Başlık adına göre
        foreach ($headers as $i => $h) {
            $n = self::norm((string) $h);
            if ($mapping['student_no'] === null && preg_match('/^(ogr(enci)?_?no|no|numara|ogrno|ogrencino|student_?no|okulno|okul_no)$/', $n)) {
                $mapping['student_no'] = $src($i);
                $used[$i] = true;
            } elseif ($mapping['booklet'] === null && preg_match('/kitap|booklet|^form$/', $n)) {
                $mapping['booklet'] = $src($i);
                $used[$i] = true;
            } elseif ($mapping['name'] === null && preg_match('/^(ad|adsoyad|ad_soyad|isim|name|full_?name|ogrenci|ogrenci_adi|adi)$/', $n)) {
                $mapping['name'] = $src($i);
                $used[$i] = true;
            } elseif (preg_match('/^(cevap|cevaplar|answers?|optik|tum_?cevaplar)$/', $n)) {
                $mapping['combined'] = $src($i);
                $used[$i] = true;
            } else {
                foreach ($sectionCounts as $code => $count) {
                    $codeN = self::norm($code);
                    $nameN = self::norm($sectionNames[$code] ?? '');
                    if ($n === $codeN || ($nameN !== '' && $n === $nameN) || $n === $codeN.'_cevap' || $n === $codeN.'cevap') {
                        $mapping['sections'][$code] = $src($i);
                        $used[$i] = true;
                        break;
                    }
                }
            }
        }

        // 2) İçeriğe göre: cevap dizisi gibi görünen kolonlar
        foreach ($headers as $i => $h) {
            if (isset($used[$i])) {
                continue;
            }
            $values = array_filter($colValues($i), fn ($v) => $v !== '');
            if ($values === []) {
                continue;
            }
            $answerLike = count(array_filter($values, fn ($v) => preg_match('/^[ABCDE\s\*\-\.]+$/i', $v) === 1 && mb_strlen($v) >= 5));
            $ratio = $answerLike / count($values);
            if ($ratio >= 0.8) {
                $lengths = array_map('mb_strlen', $values);
                $modeLen = (int) round(array_sum($lengths) / count($lengths));
                if ($mapping['combined'] === null && abs($modeLen - $total) <= 2 && $total > 0) {
                    $mapping['combined'] = $src($i);
                    $used[$i] = true;
                    continue;
                }
                foreach ($sectionCounts as $code => $count) {
                    if (! isset($mapping['sections'][$code]) && abs($modeLen - $count) <= 1) {
                        $mapping['sections'][$code] = $src($i);
                        $used[$i] = true;
                        break;
                    }
                }
                continue;
            }
            $bookletLike = count(array_filter($values, fn ($v) => preg_match('/^[AB12]$/i', $v) === 1));
            if ($mapping['booklet'] === null && $bookletLike / count($values) >= 0.9) {
                $mapping['booklet'] = $src($i);
                $used[$i] = true;
                continue;
            }
            $numeric = count(array_filter($values, fn ($v) => ctype_digit($v) && strlen($v) >= 2 && strlen($v) <= 12));
            if ($mapping['student_no'] === null && $numeric / count($values) >= 0.9) {
                $mapping['student_no'] = $src($i);
                $used[$i] = true;
                continue;
            }
            $nameLike = count(array_filter($values, fn ($v) => preg_match('/^\p{L}[\p{L}\s\.\-]+$/u', $v) === 1 && str_contains($v, ' ')));
            if ($mapping['name'] === null && $nameLike / count($values) >= 0.8) {
                $mapping['name'] = $src($i);
                $used[$i] = true;
            }
        }

        if ($mapping['combined'] !== null && count($mapping['sections']) < count($sectionCounts)) {
            $mapping['answers_mode'] = 'combined';
        }

        return $mapping;
    }

    /**
     * Sabit genişlikli satırlar için sezgisel düzen: ilk uzun rakam dizisi öğrenci no,
     * ilk uzun A-E dizisi tüm cevaplar (combined), aralarındaki tek harf kitapçık.
     *
     * @param list<string> $lines
     * @param array<string,int> $sectionCounts
     */
    public static function guessFixed(array $lines, array $sectionCounts): array
    {
        $total = array_sum($sectionCounts);
        $mapping = ['answers_mode' => 'combined', 'sections' => [], 'student_no' => null, 'name' => null, 'booklet' => null, 'combined' => null];
        $sample = array_values(array_filter(array_slice($lines, 0, 20), fn ($l) => trim($l) !== ''));
        if ($sample === []) {
            return $mapping;
        }
        $line = $sample[0];

        $chars = fn (int $byteOffset) => mb_strlen(substr($line, 0, $byteOffset));
        if (preg_match('/\d{3,12}/', $line, $m, PREG_OFFSET_CAPTURE)) {
            $mapping['student_no'] = ['start' => $chars($m[0][1]) + 1, 'length' => strlen($m[0][0])];
        }
        // En uzun A-E (boşluk/işaret dahil) dizisi
        if (preg_match_all('/[ABCDE\*\-\.][ABCDE \*\-\.]*/', $line, $all, PREG_OFFSET_CAPTURE)) {
            $best = null;
            foreach ($all[0] as $hit) {
                if ($best === null || strlen($hit[0]) > strlen($best[0])) {
                    $best = $hit;
                }
            }
            if ($best !== null && strlen($best[0]) >= max(5, (int) ($total * 0.5))) {
                $runStart = $chars($best[1]);
                $runLen = strlen($best[0]);
                // Dizi soru sayısından uzunsa baştaki fazlalık kitapçık harfidir (ör. "A" + 120 cevap)
                $answersStart = $runLen > $total ? $runStart + ($runLen - $total) : $runStart;
                $mapping['combined'] = ['start' => $answersStart + 1, 'length' => $total];
                $prev = $answersStart > 0 ? mb_substr($line, $answersStart - 1, 1) : '';
                if (in_array($prev, ['A', 'B'], true)) {
                    $mapping['booklet'] = ['start' => $answersStart, 'length' => 1];
                } else {
                    // Kitapçık: cevaplardan önceki 3 karakter içinde tek A/B harfi
                    $before = mb_substr($line, max(0, $answersStart - 3), min(3, $answersStart));
                    if (preg_match('/([AB])\s*$/', $before, $bm)) {
                        $mapping['booklet'] = ['start' => max(0, $answersStart - 3) + strpos($before, $bm[1]) + 1, 'length' => 1];
                    }
                }
            }
        }
        // Ad: öğrenci no ile cevaplar arasındaki harfli bölge
        if ($mapping['student_no'] && $mapping['combined']) {
            $from = $mapping['student_no']['start'] + $mapping['student_no']['length'] - 1;
            $to = ($mapping['booklet']['start'] ?? $mapping['combined']['start']) - 1;
            $mid = mb_substr($line, $from, max(0, $to - $from));
            if (preg_match('/\p{L}{2,}/u', $mid)) {
                $mapping['name'] = ['start' => $from + 1, 'length' => $to - $from];
            }
        }

        return $mapping;
    }

    /** Mapping doğrulaması: eksik zorunlu alanlar Türkçe mesaj listesi olarak döner. */
    public static function validate(array $mapping, array $sectionCounts): array
    {
        $errors = [];
        if (empty($mapping['student_no'])) {
            $errors[] = 'Öğrenci numarası kolonu seçilmedi.';
        }
        if (($mapping['answers_mode'] ?? 'per_section') === 'combined') {
            if (empty($mapping['combined'])) {
                $errors[] = 'Tüm cevapların bulunduğu kolon seçilmedi.';
            }
        } else {
            foreach ($sectionCounts as $code => $count) {
                if (empty($mapping['sections'][$code])) {
                    $errors[] = "{$code} bölümü için cevap kolonu seçilmedi.";
                }
            }
        }

        return $errors;
    }

    public static function padAnswers(string $raw, int $count): string
    {
        $raw = strtoupper($raw);
        $raw = preg_replace('/[^ABCDE]/', ' ', $raw) ?? '';

        return str_pad(mb_substr($raw, 0, $count), $count, ' ');
    }

    private static function scalar(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function clean(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** Türkçe karakterleri sadeleştirip küçük harfe çevirir (başlık karşılaştırması). */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i̇' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;

        return trim($s, '_');
    }
}
