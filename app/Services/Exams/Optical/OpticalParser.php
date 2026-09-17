<?php

namespace App\Services\Exams\Optical;

use App\Exceptions\BusinessRuleException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Optik okuyucu çıktılarını satırlara ayırır (veritabanına dokunmaz).
 *
 * Desteklenen biçimler:
 *  - csv   : ayraç otomatik algılanır (; , TAB |), başlık satırı sezgisel bulunur
 *  - xlsx  : ilk sayfa (openspout)
 *  - txt   : sabit genişlikli satırlar (kolon düzeni ColumnMapper ile tanımlanır);
 *            satırlar tutarlı biçimde ayraç içeriyorsa otomatik olarak "delimited" sayılır
 *  - json  : nesne dizisi ya da {rows:[…]} / {data:[…]}
 */
final class OpticalParser
{
    public const DELIMITERS = [';', ',', "\t", '|'];

    /** Dosya adı + içerikten biçim: csv | xlsx | txt | json */
    public static function detectFormat(string $fileName, string $head = ''): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xlsm', 'xls'], true)) {
            return 'xlsx';
        }
        if ($ext === 'json') {
            return 'json';
        }
        if ($ext === 'csv') {
            return 'csv';
        }
        $trim = ltrim($head);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            return 'json';
        }
        if ($ext === 'txt' || $ext === 'dat' || $ext === 'prn' || $ext === '') {
            return self::looksDelimited($head) ? 'csv' : 'txt';
        }

        return 'csv';
    }

    /**
     * @return array{kind:'delimited'|'fixed'|'json', delimiter:?string, has_header:bool, headers:list<string>, rows:list<array<int,string>|string|array<string,mixed>>}
     */
    public static function parse(string $format, string $content, ?string $path = null): array
    {
        return match ($format) {
            'xlsx' => self::parseXlsx($path ?? throw new BusinessRuleException('XLSX dosyası bulunamadı.', 'optical_file_missing')),
            'json' => self::parseJson($content),
            'txt' => self::looksDelimited($content) ? self::parseDelimited($content) : self::parseFixedWidth($content),
            default => self::parseDelimited($content),
        };
    }

    /** Satırların çoğunda aynı ayraç en az 2 kez geçiyorsa ayraçlı sayılır. */
    public static function looksDelimited(string $content): bool
    {
        $lines = array_slice(self::lines($content), 0, 30);
        if ($lines === []) {
            return false;
        }

        foreach (self::DELIMITERS as $d) {
            $hits = 0;
            foreach ($lines as $line) {
                if (substr_count($line, $d) >= 2) {
                    $hits++;
                }
            }
            if ($hits >= max(1, (int) ceil(count($lines) * 0.8))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{kind:'delimited', delimiter:string, has_header:bool, headers:list<string>, rows:list<array<int,string>>} */
    public static function parseDelimited(string $content): array
    {
        $lines = self::lines($content);
        $delimiter = self::detectDelimiter($lines);

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $cells = array_map(fn ($c) => trim((string) $c), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            $rows[] = $cells;
        }

        $hasHeader = $rows !== [] && self::looksLikeHeader($rows[0]);
        $headers = $hasHeader ? array_shift($rows) : self::defaultHeaders(max(array_map('count', $rows ?: [[]])));

        return ['kind' => 'delimited', 'delimiter' => $delimiter, 'has_header' => $hasHeader, 'headers' => $headers, 'rows' => $rows];
    }

    /** @return array{kind:'fixed', delimiter:null, has_header:false, headers:list<string>, rows:list<string>} */
    public static function parseFixedWidth(string $content): array
    {
        $rows = array_values(array_filter(self::lines($content), fn ($l) => trim($l) !== ''));

        return ['kind' => 'fixed', 'delimiter' => null, 'has_header' => false, 'headers' => [], 'rows' => $rows];
    }

    /** @return array{kind:'json', delimiter:null, has_header:true, headers:list<string>, rows:list<array<string,mixed>>} */
    public static function parseJson(string $content): array
    {
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new BusinessRuleException('JSON dosyası çözümlenemedi. Nesne dizisi bekleniyor.', 'optical_json_invalid');
        }
        $rows = $decoded['rows'] ?? $decoded['data'] ?? $decoded;
        if (! array_is_list($rows)) {
            throw new BusinessRuleException('JSON içeriği bir satır listesi olmalı ([{...},{...}]).', 'optical_json_invalid');
        }
        $rows = array_values(array_filter($rows, 'is_array'));
        $headers = [];
        foreach (array_slice($rows, 0, 50) as $r) {
            foreach (array_keys($r) as $k) {
                $headers[$k] = true;
            }
        }

        return ['kind' => 'json', 'delimiter' => null, 'has_header' => true, 'headers' => array_map('strval', array_keys($headers)), 'rows' => $rows];
    }

    /** @return array{kind:'delimited', delimiter:null, has_header:bool, headers:list<string>, rows:list<array<int,string>>} */
    public static function parseXlsx(string $path): array
    {
        $reader = new XlsxReader();
        $reader->open($path);
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(function ($v) {
                        if ($v instanceof \DateTimeInterface) {
                            return $v->format('Y-m-d');
                        }

                        return trim((string) $v);
                    }, $row->toArray());
                    if (implode('', $cells) === '') {
                        continue;
                    }
                    $rows[] = $cells;
                }
                break; // yalnız ilk sayfa
            }
        } finally {
            $reader->close();
        }

        $hasHeader = $rows !== [] && self::looksLikeHeader($rows[0]);
        $headers = $hasHeader ? array_shift($rows) : self::defaultHeaders(max(array_map('count', $rows ?: [[]])));

        return ['kind' => 'delimited', 'delimiter' => null, 'has_header' => $hasHeader, 'headers' => $headers, 'rows' => $rows];
    }

    /** @param list<string> $lines */
    public static function detectDelimiter(array $lines): string
    {
        $sample = array_slice($lines, 0, 25);
        $best = ';';
        $bestScore = -1;
        foreach (self::DELIMITERS as $d) {
            $counts = array_map(fn ($l) => substr_count($l, $d), $sample);
            $nonZero = array_filter($counts);
            if ($nonZero === []) {
                continue;
            }
            // Tutarlılık: satırlar arasında aynı sayıda ayraç → yüksek puan
            $score = count($nonZero) * 10 - (max($counts) - min($nonZero)) + min($nonZero);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        return $best;
    }

    /** İlk satırda harf içeren (cevap dizisi olmayan) hücre çoğunluktaysa başlık kabul edilir. */
    public static function looksLikeHeader(array $cells): bool
    {
        $textual = 0;
        $filled = 0;
        foreach ($cells as $c) {
            $c = trim((string) $c);
            if ($c === '') {
                continue;
            }
            $filled++;
            $isAnswerLike = preg_match('/^[ABCDE\s\*\-\.]+$/i', $c) === 1;
            $isNumeric = is_numeric($c);
            if (! $isAnswerLike && ! $isNumeric && preg_match('/\p{L}/u', $c)) {
                $textual++;
            }
        }

        return $filled > 0 && $textual >= max(1, (int) ceil($filled / 2));
    }

    /** @return list<string> */
    public static function lines(string $content): array
    {
        $content = self::toUtf8($content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        return preg_split('/\r\n|\r|\n/', rtrim($content, "\r\n")) ?: [];
    }

    /** Windows-1254 (Türkçe) ya da ISO-8859-9 içeriği UTF-8'e çevirir. */
    public static function toUtf8(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        $converted = @mb_convert_encoding($content, 'UTF-8', 'Windows-1254');

        return is_string($converted) ? $converted : $content;
    }

    /** @return list<string> */
    private static function defaultHeaders(int $count): array
    {
        return array_map(fn ($i) => 'Kolon '.($i + 1), range(0, max(0, $count - 1)));
    }
}
