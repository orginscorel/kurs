<?php

namespace App\Services\Exams\Optical;

use App\Services\Exams\ExamScoring;

/**
 * Kitapçık algılama (saf).
 *
 * 1) Optik dosyadaki kitapçık değeri normalize edilir: "A", "a", "1", "K-A", "B", "2" …
 * 2) Değer yoksa ve sınavda birden fazla kitapçık varsa öğrencinin cevapları her
 *    kitapçık sırasına göre puanlanır; en çok doğru veren kitapçık seçilir.
 */
final class BookletDetector
{
    /** @param list<string> $allowed */
    public static function normalize(mixed $raw, array $allowed = ['A', 'B']): ?string
    {
        if ($raw === null) {
            return null;
        }
        $v = strtoupper(trim((string) $raw));
        if ($v === '') {
            return null;
        }
        $v = str_replace(['İ', 'I'], 'I', $v);
        // "K-A", "KİTAPÇIK B", "A KİTAPÇIĞI" gibi ifadelerden harfi ayıkla
        if (preg_match('/\b([A-D])\b/', $v, $m) && in_array($m[1], $allowed, true)) {
            return $m[1];
        }
        if (preg_match('/^[A-D]$/', $v) && in_array($v, $allowed, true)) {
            return $v;
        }
        if (ctype_digit($v)) {
            $letter = chr(64 + (int) $v); // 1 → A, 2 → B
            if (in_array($letter, $allowed, true)) {
                return $letter;
            }
        }
        // İlk karakter A-D ise (ör. "A1")
        $first = $v[0];
        if (in_array($first, $allowed, true)) {
            return $first;
        }

        return null;
    }

    /**
     * Cevapları her kitapçığa göre puanlayıp en yüksek doğru sayısını veren kitapçığı döner.
     *
     * @param array<string, string> $answersBySection bölüm kodu => kitapçık sırasındaki cevaplar
     * @param array<string, list<array{number:int, booklet_map:array<string, array{no:int, answer:string}>, is_cancelled:bool}>> $questionsBySection
     * @param list<string> $booklets
     * @return array{booklet:string, scores:array<string,int>, confident:bool}
     */
    public static function detect(array $answersBySection, array $questionsBySection, array $booklets): array
    {
        $scores = [];
        foreach ($booklets as $booklet) {
            $correct = 0;
            foreach ($questionsBySection as $code => $questions) {
                $canonical = ExamScoring::toCanonical($answersBySection[$code] ?? '', $booklet, $questions);
                $correct += ExamScoring::scoreSection($canonical, $questions, 0)['correct'];
            }
            $scores[$booklet] = $correct;
        }
        arsort($scores, SORT_NUMERIC);
        $values = array_values($scores);
        $best = (string) array_key_first($scores);
        // İki kitapçık arasındaki fark küçükse (ör. neredeyse boş kâğıt) güven düşüktür.
        $confident = count($values) < 2 || ($values[0] - $values[1]) >= max(2, (int) round($values[0] * 0.1));

        return ['booklet' => $best, 'scores' => $scores, 'confident' => $confident];
    }
}
