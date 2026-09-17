<?php

namespace Tests\Unit;

use App\Services\Exams\Optical\OpticalParser;
use PHPUnit\Framework\TestCase;

class OpticalParserTest extends TestCase
{
    public function test_detects_format_from_extension_and_content(): void
    {
        $this->assertSame('xlsx', OpticalParser::detectFormat('optik.xlsx'));
        $this->assertSame('json', OpticalParser::detectFormat('optik.json'));
        $this->assertSame('json', OpticalParser::detectFormat('optik.txt', '[{"student_no":"1"}]'));
        $this->assertSame('csv', OpticalParser::detectFormat('optik.csv'));
        $this->assertSame('txt', OpticalParser::detectFormat('optik.txt', "2026001AABCDE\n2026002BBBCDE\n"));
        $this->assertSame('csv', OpticalParser::detectFormat('optik.txt', "2026001;A;ABCDE\n2026002;B;BBCDE\n"));
    }

    public function test_parses_semicolon_csv_with_header(): void
    {
        $csv = "Öğrenci No;Ad Soyad;Kitapçık;Türkçe;Matematik\r\n2026001;Ayşe Yılmaz;A;ABCDE;EDCBA\r\n2026002;Ali Kaya;B;AB DE;E CBA\r\n";
        $p = OpticalParser::parseDelimited($csv);
        $this->assertSame(';', $p['delimiter']);
        $this->assertTrue($p['has_header']);
        $this->assertSame(['Öğrenci No', 'Ad Soyad', 'Kitapçık', 'Türkçe', 'Matematik'], $p['headers']);
        $this->assertCount(2, $p['rows']);
        $this->assertSame(['2026002', 'Ali Kaya', 'B', 'AB DE', 'E CBA'], $p['rows'][1]);
    }

    public function test_parses_comma_csv_without_header(): void
    {
        $csv = "2026001,A,ABCDEABCDE\n2026002,B,EDCBAEDCBA\n\n";
        $p = OpticalParser::parseDelimited($csv);
        $this->assertSame(',', $p['delimiter']);
        $this->assertFalse($p['has_header']);
        $this->assertSame(['Kolon 1', 'Kolon 2', 'Kolon 3'], $p['headers']);
        $this->assertCount(2, $p['rows']);
    }

    public function test_fixed_width_keeps_raw_lines(): void
    {
        $txt = "2026001AYSE YILMAZ        AABCDE\n\n2026002ALI KAYA           BEDCBA\n";
        $p = OpticalParser::parseFixedWidth($txt);
        $this->assertSame('fixed', $p['kind']);
        $this->assertCount(2, $p['rows']);
        $this->assertSame('2026002ALI KAYA           BEDCBA', $p['rows'][1]);
    }

    public function test_windows_1254_content_is_converted_to_utf8(): void
    {
        $latin = mb_convert_encoding("2026001;Şule Çınar;A;ABCDE\n", 'Windows-1254', 'UTF-8');
        $p = OpticalParser::parseDelimited($latin);
        $this->assertSame('Şule Çınar', $p['rows'][0][1]);
    }

    public function test_json_rows_are_parsed_with_headers(): void
    {
        $p = OpticalParser::parseJson('{"rows":[{"student_no":"2026001","booklet":"A","answers":"ABCDE"},{"student_no":"2026002","answers":"EDCBA"}]}');
        $this->assertSame('json', $p['kind']);
        $this->assertSame(['student_no', 'booklet', 'answers'], $p['headers']);
        $this->assertCount(2, $p['rows']);
    }

    public function test_header_detection_ignores_answer_like_rows(): void
    {
        $this->assertFalse(OpticalParser::looksLikeHeader(['2026001', 'A', 'ABCDE ABC', 'EDCBA']));
        $this->assertTrue(OpticalParser::looksLikeHeader(['No', 'Kitapçık', 'TUR', 'MAT']));
    }
}
