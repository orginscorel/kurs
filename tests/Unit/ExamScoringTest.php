<?php

namespace Tests\Unit;

use App\Services\Exams\ExamScoring;
use PHPUnit\Framework\TestCase;

class ExamScoringTest extends TestCase
{
    /** A kitapçığında 1..5, B kitapçığında sıra ters. Anahtar: A B C D E */
    private function questions(bool $cancelThird = false): array
    {
        $keys = ['A', 'B', 'C', 'D', 'E'];
        $out = [];
        foreach ($keys as $i => $k) {
            $out[] = [
                'number' => $i + 1,
                'booklet_map' => ['A' => ['no' => $i + 1, 'answer' => $k], 'B' => ['no' => 5 - $i, 'answer' => $k]],
                'is_cancelled' => $cancelThird && $i === 2,
            ];
        }

        return $out;
    }

    public function test_net_is_correct_minus_quarter_of_wrong(): void
    {
        // 3 doğru (A,B,C), 1 yanlış (E yerine D… 4. soru D doğru, 5. soru yanlış), 1 boş
        $result = ExamScoring::scoreSection('ABCD ', $this->questions(), 0.25);
        $this->assertSame(['correct' => 4, 'wrong' => 0, 'blank' => 1, 'net' => 4.0], $result);

        $result = ExamScoring::scoreSection('ABCAA', $this->questions(), 0.25);
        $this->assertSame(3, $result['correct']);
        $this->assertSame(2, $result['wrong']);
        $this->assertSame(2.5, $result['net']);
    }

    public function test_four_wrong_cancel_one_correct(): void
    {
        $result = ExamScoring::scoreSection('BAAAA', $this->questions(), 0.25);
        $this->assertSame(0, $result['correct']);
        $this->assertSame(5, $result['wrong']);
        $this->assertSame(-1.25, $result['net']);
    }

    public function test_booklet_b_answers_are_mapped_to_canonical_order(): void
    {
        // B kitapçığında soru sırası ters: öğrenci B'de "EDCBA" işaretlediyse A sırasında "ABCDE" olur
        $canonical = ExamScoring::toCanonical('EDCBA', 'B', $this->questions());
        $this->assertSame('ABCDE', $canonical);
        $this->assertSame(5, ExamScoring::scoreSection($canonical, $this->questions(), 0.25)['correct']);
    }

    public function test_cancelled_question_counts_as_correct_for_everyone(): void
    {
        $result = ExamScoring::scoreSection('AB DE', $this->questions(cancelThird: true), 0.25);
        $this->assertSame(5, $result['correct']);
        $this->assertSame(0, $result['blank']);
    }

    public function test_optical_noise_is_treated_as_blank(): void
    {
        $this->assertSame('A  D ', ExamScoring::normalizeAnswers('a*-d.'));
    }

    public function test_short_answer_string_pads_with_blanks(): void
    {
        $result = ExamScoring::scoreSection('AB', $this->questions(), 0.25);
        $this->assertSame(2, $result['correct']);
        $this->assertSame(3, $result['blank']);
    }

    public function test_score_uses_base_plus_weighted_nets(): void
    {
        $score = ExamScoring::score(['TUR' => 30.0, 'MAT' => 20.0], ['TUR' => 3.3, 'MAT' => 3.3], 100);
        $this->assertSame(265.0, $score);
    }

    public function test_competition_ranking_gives_ties_same_rank(): void
    {
        $ranks = ExamScoring::rank([10 => 300.5, 11 => 280.0, 12 => 300.5, 13 => 250.0]);
        $this->assertSame(1, $ranks[10]);
        $this->assertSame(1, $ranks[12]);
        $this->assertSame(3, $ranks[11]);
        $this->assertSame(4, $ranks[13]);
    }
}
