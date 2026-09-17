<?php

namespace App\Services\Exams;

/**
 * Saf puanlama mantığı (veritabanına dokunmaz, birim testlenebilir).
 *
 * Kurallar:
 * - net = doğru − yanlış × ceza oranı (TYT/AYT: 0,25 → 4 yanlış 1 doğruyu götürür)
 * - İptal edilen soru, cevap ne olursa olsun herkese DOĞRU sayılır.
 * - Cevaplar kitapçık sırasıyla gelir; kanonik (A) sıraya çevrilerek saklanır ve analiz edilir.
 * - puan = taban puan + Σ(bölüm neti × katsayı)
 */
final class ExamScoring
{
    public const BLANK = ' ';

    /**
     * @param list<array{number:int, booklet_map:array<string, array{no:int, answer:string}>, is_cancelled:bool}> $questions
     * @return string Kanonik sırada cevap dizisi (her soru 1 karakter, boş = ' ')
     */
    public static function toCanonical(string $bookletAnswers, string $booklet, array $questions): string
    {
        $raw = self::normalizeAnswers($bookletAnswers);
        $out = '';

        foreach ($questions as $q) {
            $position = ($q['booklet_map'][$booklet]['no'] ?? $q['number']) - 1;
            $out .= $raw[$position] ?? self::BLANK;
        }

        return $out;
    }

    /**
     * @param list<array{number:int, booklet_map:array<string, array{no:int, answer:string}>, is_cancelled:bool}> $questions
     * @return array{correct:int, wrong:int, blank:int, net:float}
     */
    public static function scoreSection(string $canonicalAnswers, array $questions, float $penaltyRatio): array
    {
        $correct = $wrong = $blank = 0;

        foreach ($questions as $i => $q) {
            $given = $canonicalAnswers[$i] ?? self::BLANK;
            // Anahtar kanonik (A) kitapçıktan okunur; soru içeriği kitapçıklar arasında aynıdır.
            $key = strtoupper($q['booklet_map']['A']['answer'] ?? reset($q['booklet_map'])['answer'] ?? '');

            if ($q['is_cancelled']) {
                $correct++;
            } elseif ($given === self::BLANK) {
                $blank++;
            } elseif ($given === $key) {
                $correct++;
            } else {
                $wrong++;
            }
        }

        return [
            'correct' => $correct,
            'wrong' => $wrong,
            'blank' => $blank,
            'net' => round($correct - $wrong * $penaltyRatio, 2),
        ];
    }

    /**
     * @param array<string, float> $sectionNets   bölüm kodu => net
     * @param array<string, float> $coefficients  bölüm kodu => katsayı
     */
    public static function score(array $sectionNets, array $coefficients, float $baseScore): float
    {
        $total = $baseScore;

        foreach ($sectionNets as $code => $net) {
            $total += $net * ($coefficients[$code] ?? 0);
        }

        return round(max(0, $total), 3);
    }

    /**
     * Yarışma sıralaması (1,2,2,4): eşit puan eşit sıra alır.
     *
     * @param array<int|string, float> $scores  anahtar => puan
     * @return array<int|string, int>
     */
    public static function rank(array $scores): array
    {
        arsort($scores, SORT_NUMERIC);
        $ranks = [];
        $position = 0;
        $lastScore = null;
        $lastRank = 0;

        foreach ($scores as $key => $score) {
            $position++;
            if ($lastScore === null || abs($score - $lastScore) > 0.0001) {
                $lastRank = $position;
                $lastScore = $score;
            }
            $ranks[$key] = $lastRank;
        }

        return $ranks;
    }

    /** Optik okuyucu çıktısını temizler: '*', '-', '.', boşluk → boş; birden fazla işaret → boş sayılır. */
    public static function normalizeAnswers(string $answers): string
    {
        $answers = strtoupper($answers);

        return preg_replace('/[^ABCDE]/', self::BLANK, $answers);
    }
}
