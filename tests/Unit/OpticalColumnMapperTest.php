<?php

namespace Tests\Unit;

use App\Services\Exams\Optical\ColumnMapper;
use PHPUnit\Framework\TestCase;

class OpticalColumnMapperTest extends TestCase
{
    private array $counts = ['TUR' => 5, 'MAT' => 5];

    public function test_maps_delimited_row_per_section(): void
    {
        $mapping = ['student_no' => ['col' => 0], 'name' => ['col' => 1], 'booklet' => ['col' => 2], 'answers_mode' => 'per_section', 'sections' => ['TUR' => ['col' => 3], 'MAT' => ['col' => 4]]];
        $m = ColumnMapper::map(['2026001', 'Ayşe Yılmaz', 'A', 'abc*e', 'ED'], $mapping, $this->counts);

        $this->assertSame('2026001', $m['student_no']);
        $this->assertSame('Ayşe Yılmaz', $m['name']);
        $this->assertSame('A', $m['booklet']);
        $this->assertSame('ABC E', $m['answers']['TUR']);   // '*' → boş, büyük harf
        $this->assertSame('ED   ', $m['answers']['MAT']);   // eksik cevaplar boşla doldurulur
    }

    public function test_maps_combined_answers_and_slices_sections_in_exam_order(): void
    {
        $mapping = ['student_no' => ['col' => 0], 'booklet' => ['fixed' => 'A'], 'answers_mode' => 'combined', 'combined' => ['col' => 1]];
        $m = ColumnMapper::map(['7', 'ABCDEEDCBAXX'], $mapping, $this->counts);

        $this->assertSame('ABCDE', $m['answers']['TUR']);
        $this->assertSame('EDCBA', $m['answers']['MAT']);
        $this->assertSame('A', $m['booklet']);
    }

    public function test_maps_fixed_width_line(): void
    {
        $line = '2026001AYSE YILMAZ        AABCDEEDCBA';
        $mapping = ['student_no' => ['start' => 1, 'length' => 7], 'name' => ['start' => 8, 'length' => 19], 'booklet' => ['start' => 27, 'length' => 1], 'answers_mode' => 'combined', 'combined' => ['start' => 28]];
        $m = ColumnMapper::map($line, $mapping, $this->counts);

        $this->assertSame('2026001', $m['student_no']);
        $this->assertSame('AYSE YILMAZ', $m['name']);
        $this->assertSame('A', $m['booklet']);
        $this->assertSame('ABCDE', $m['answers']['TUR']);
        $this->assertSame('EDCBA', $m['answers']['MAT']);
    }

    public function test_maps_json_row_by_key(): void
    {
        $mapping = ['student_no' => ['key' => 'no'], 'booklet' => ['key' => 'kitapcik'], 'answers_mode' => 'per_section', 'sections' => ['TUR' => ['key' => 'tur'], 'MAT' => ['key' => 'mat']]];
        $m = ColumnMapper::map(['no' => 2026001, 'kitapcik' => 'B', 'tur' => 'ABCDE', 'mat' => 'EDCBA'], $mapping, $this->counts);
        $this->assertSame('2026001', $m['student_no']);
        $this->assertSame('B', $m['booklet']);
        $this->assertSame('EDCBA', $m['answers']['MAT']);
    }

    public function test_guesses_mapping_from_turkish_headers(): void
    {
        $headers = ['Öğrenci No', 'Ad Soyad', 'Kitapçık', 'Türkçe', 'MAT'];
        $rows = [['2026001', 'Ayşe Yılmaz', 'A', 'ABCDE', 'EDCBA'], ['2026002', 'Ali Kaya', 'B', 'AB DE', 'E CBA']];
        $g = ColumnMapper::guess($headers, $rows, $this->counts, ['TUR' => 'Türkçe', 'MAT' => 'Matematik']);

        $this->assertSame(['col' => 0], $g['student_no']);
        $this->assertSame(['col' => 1], $g['name']);
        $this->assertSame(['col' => 2], $g['booklet']);
        $this->assertSame(['col' => 3], $g['sections']['TUR']);
        $this->assertSame(['col' => 4], $g['sections']['MAT']);
        $this->assertSame('per_section', $g['answers_mode']);
    }

    public function test_guesses_combined_column_from_content_without_headers(): void
    {
        $headers = ['Kolon 1', 'Kolon 2', 'Kolon 3'];
        $rows = [['2026001', 'A', 'ABCDEEDCBA'], ['2026002', 'B', 'AB DEE CBA'], ['2026003', 'A', 'ABCDEEDCB ']];
        $g = ColumnMapper::guess($headers, $rows, $this->counts);

        $this->assertSame(['col' => 0], $g['student_no']);
        $this->assertSame(['col' => 1], $g['booklet']);
        $this->assertSame(['col' => 2], $g['combined']);
        $this->assertSame('combined', $g['answers_mode']);
    }

    public function test_guesses_fixed_width_layout(): void
    {
        $lines = ['2026001AYSE YILMAZ        AABCDEEDCBA', '2026002ALI KAYA           BEDCBAABCDE'];
        $g = ColumnMapper::guessFixed($lines, $this->counts);

        $this->assertSame(['start' => 1, 'length' => 7], $g['student_no']);
        $this->assertSame(['start' => 28, 'length' => 10], $g['combined']);
        $this->assertSame(['start' => 27, 'length' => 1], $g['booklet']);
        $this->assertSame('ABCDE', ColumnMapper::map($lines[1], $g, $this->counts)['answers']['MAT']);
    }

    public function test_json_nested_answers_object_is_guessed_and_mapped(): void
    {
        $rows = [['student_no' => '2026001', 'booklet' => 'A', 'answers' => ['TUR' => 'ABCDE', 'MAT' => 'EDCBA']]];
        $g = ColumnMapper::guess(['student_no', 'booklet', 'answers'], $rows, $this->counts, [], 'json');

        $this->assertSame(['key' => 'student_no'], $g['student_no']);
        $this->assertSame(['key' => 'booklet'], $g['booklet']);
        $this->assertSame(['key' => 'answers.TUR'], $g['sections']['TUR']);
        $this->assertSame('per_section', $g['answers_mode']);
        $m = ColumnMapper::map($rows[0], $g, $this->counts);
        $this->assertSame('EDCBA', $m['answers']['MAT']);
        // dizi değer string kolona düşerse hata yerine boş
        $this->assertSame('     ', ColumnMapper::map($rows[0], ['student_no' => ['key' => 'student_no'], 'answers_mode' => 'combined', 'combined' => ['key' => 'answers']], $this->counts)['answers']['TUR']);
    }

    public function test_fixed_width_guess_uses_character_offsets_for_turkish_names(): void
    {
        $lines = ['2026001Şule Çınar         BEDCBAABCDE', '2026002Ali Kaya           AABCDEEDCBA'];
        $g = ColumnMapper::guessFixed($lines, $this->counts);

        $this->assertSame(['start' => 27, 'length' => 1], $g['booklet']);
        $this->assertSame(['start' => 28, 'length' => 10], $g['combined']);
        $m = ColumnMapper::map($lines[0], $g, $this->counts);
        $this->assertSame('B', $m['booklet']);
        $this->assertSame('EDCBA', $m['answers']['TUR']);
        $this->assertSame('Şule Çınar', $m['name']);
    }

    public function test_section_code_tur_is_not_mistaken_for_booklet(): void
    {
        $g = ColumnMapper::guess(['No', 'TUR', 'MAT'], [['1', 'ABCDE', 'EDCBA']], $this->counts);
        $this->assertNull($g['booklet']);
        $this->assertSame(['col' => 1], $g['sections']['TUR']);
    }

    public function test_validate_reports_missing_sources(): void
    {
        $errors = ColumnMapper::validate(['answers_mode' => 'per_section', 'sections' => ['TUR' => ['col' => 1]]], $this->counts);
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Öğrenci numarası', $errors[0]);
        $this->assertStringContainsString('MAT', $errors[1]);
        $this->assertSame([], ColumnMapper::validate(['student_no' => ['col' => 0], 'answers_mode' => 'combined', 'combined' => ['col' => 1]], $this->counts));
    }
}
