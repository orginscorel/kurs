<?php

namespace Tests\Unit;

use App\Services\Exams\Optical\BookletDetector;
use PHPUnit\Framework\TestCase;

class OpticalBookletDetectorTest extends TestCase
{
    /** A: 1..5, B: ters sıra. Anahtar A B C D E */
    private function questions(): array
    {
        $out = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $i => $k) {
            $out[] = ['number' => $i + 1, 'booklet_map' => ['A' => ['no' => $i + 1, 'answer' => $k], 'B' => ['no' => 5 - $i, 'answer' => $k]], 'is_cancelled' => false];
        }

        return $out;
    }

    public function test_normalizes_common_booklet_notations(): void
    {
        $this->assertSame('A', BookletDetector::normalize('a'));
        $this->assertSame('B', BookletDetector::normalize(' B '));
        $this->assertSame('A', BookletDetector::normalize('1'));
        $this->assertSame('B', BookletDetector::normalize(2));
        $this->assertSame('B', BookletDetector::normalize('Kitapçık B'));
        $this->assertSame('A', BookletDetector::normalize('K-A'));
        $this->assertNull(BookletDetector::normalize(''));
        $this->assertNull(BookletDetector::normalize(null));
        $this->assertNull(BookletDetector::normalize('C', ['A', 'B']));
    }

    public function test_detects_booklet_by_best_score(): void
    {
        $q = ['TUR' => $this->questions()];
        // Öğrenci B kitapçığında "EDCBA" işaretledi → B'de 5 doğru, A'da 1 doğru (ortadaki C)
        $d = BookletDetector::detect(['TUR' => 'EDCBA'], $q, ['A', 'B']);
        $this->assertSame('B', $d['booklet']);
        $this->assertSame(5, $d['scores']['B']);
        $this->assertSame(1, $d['scores']['A']);
        $this->assertTrue($d['confident']);

        $d = BookletDetector::detect(['TUR' => 'ABCDE'], $q, ['A', 'B']);
        $this->assertSame('A', $d['booklet']);
    }

    public function test_blank_sheet_is_not_confident(): void
    {
        $d = BookletDetector::detect(['TUR' => '     '], ['TUR' => $this->questions()], ['A', 'B']);
        $this->assertFalse($d['confident']);
    }
}
