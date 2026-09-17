<?php

namespace App\Services\Exams\Report;

use GdImage;

/**
 * GD üzerinde küçük bir çizim kiti: TTF metin (Türkçe karakter destekli DejaVu Sans),
 * yuvarlak köşeli kutu, ilerleme çubuğu, mini çizgi grafik (sparkline), görsel yerleştirme.
 * Şablonlar yalnızca bu sınıfı kullanır; GD ayrıntısı şablona sızmaz.
 */
final class Canvas
{
    public const FONT_REGULAR = 'DejaVuSans.ttf';

    public const FONT_BOLD = 'DejaVuSans-Bold.ttf';

    private GdImage $im;

    /** @var array<string,int> */
    private array $colors = [];

    public function __construct(public readonly int $width, public readonly int $height, string $background = '#ffffff')
    {
        $this->im = imagecreatetruecolor($width, $height);
        imagealphablending($this->im, true);
        imagesavealpha($this->im, true);
        imageantialias($this->im, true);
        imagefilledrectangle($this->im, 0, 0, $width, $height, $this->color($background));
    }

    public static function fontPath(string $file): string
    {
        return resource_path('fonts/'.$file);
    }

    public function color(string $hex, int $alpha = 0): int
    {
        $key = $hex.'/'.$alpha;
        if (! isset($this->colors[$key])) {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
            $this->colors[$key] = imagecolorallocatealpha($this->im, $r, $g, $b, max(0, min(127, $alpha)));
        }

        return $this->colors[$key];
    }

    public function rect(int $x, int $y, int $w, int $h, string $hex, int $alpha = 0): void
    {
        imagefilledrectangle($this->im, $x, $y, $x + $w - 1, $y + $h - 1, $this->color($hex, $alpha));
    }

    public function roundedRect(int $x, int $y, int $w, int $h, int $r, string $hex, int $alpha = 0): void
    {
        $c = $this->color($hex, $alpha);
        $r = max(0, min($r, intdiv(min($w, $h), 2)));
        imagefilledrectangle($this->im, $x + $r, $y, $x + $w - $r - 1, $y + $h - 1, $c);
        imagefilledrectangle($this->im, $x, $y + $r, $x + $w - 1, $y + $h - $r - 1, $c);
        foreach ([[$x + $r, $y + $r], [$x + $w - $r - 1, $y + $r], [$x + $r, $y + $h - $r - 1], [$x + $w - $r - 1, $y + $h - $r - 1]] as [$cx, $cy]) {
            imagefilledellipse($this->im, $cx, $cy, $r * 2, $r * 2, $c);
        }
    }

    public function circle(int $cx, int $cy, int $d, string $hex): void
    {
        imagefilledellipse($this->im, $cx, $cy, $d, $d, $this->color($hex));
    }

    public function line(int $x1, int $y1, int $x2, int $y2, string $hex, int $thickness = 1): void
    {
        imagesetthickness($this->im, $thickness);
        imageline($this->im, $x1, $y1, $x2, $y2, $this->color($hex));
        imagesetthickness($this->im, 1);
    }

    /**
     * Metin yazar; taban çizgisi y'dir. Genişliği döner.
     *
     * @param 'left'|'center'|'right' $align
     */
    public function text(int $x, int $y, string $text, int $px, string $hex, bool $bold = false, string $align = 'left', ?int $maxWidth = null): int
    {
        $text = $this->escape($text);
        $font = self::fontPath($bold ? self::FONT_BOLD : self::FONT_REGULAR);
        $pt = $px * 0.75;
        if ($maxWidth !== null) {
            $text = $this->truncate($text, $pt, $font, $maxWidth);
        }
        $w = $this->measure($text, $pt, $font);
        $dx = match ($align) { 'center' => -intdiv($w, 2), 'right' => -$w, default => 0 };
        imagettftext($this->im, $pt, 0, $x + $dx, $y, $this->color($hex), $font, $text);

        return $w;
    }

    /** Verilen genişliğe sığana kadar punto küçültür (en az $minPx). */
    public function fitText(int $x, int $y, string $text, int $px, int $minPx, string $hex, bool $bold, string $align, int $maxWidth): int
    {
        $font = self::fontPath($bold ? self::FONT_BOLD : self::FONT_REGULAR);
        $escaped = $this->escape($text);
        while ($px > $minPx && $this->measure($escaped, $px * 0.75, $font) > $maxWidth) {
            $px -= 2;
        }

        return $this->text($x, $y, $text, $px, $hex, $bold, $align, $maxWidth);
    }

    public function textWidth(string $text, int $px, bool $bold = false): int
    {
        return $this->measure($this->escape($text), $px * 0.75, self::fontPath($bold ? self::FONT_BOLD : self::FONT_REGULAR));
    }

    /** Yatay ilerleme çubuğu. $pct 0..100 */
    public function bar(int $x, int $y, int $w, int $h, float $pct, string $hex, string $track = '#ebebf0'): void
    {
        $this->roundedRect($x, $y, $w, $h, intdiv($h, 2), $track);
        $fill = (int) round($w * max(0, min(100, $pct)) / 100);
        if ($fill > 0) {
            $this->roundedRect($x, $y, max($fill, $h), $h, intdiv($h, 2), $hex);
        }
    }

    /**
     * Mini çizgi grafik: değerler soldan sağa, alan dolgusu + noktalar.
     *
     * @param list<float> $values
     */
    public function sparkline(int $x, int $y, int $w, int $h, array $values, string $hex, string $fillHex, ?float $min = null, ?float $max = null): void
    {
        $n = count($values);
        if ($n === 0) {
            return;
        }
        $min ??= min($values);
        $max ??= max($values);
        if ($max - $min < 1e-6) {
            $max = $min + 1;
        }
        $points = [];
        foreach ($values as $i => $v) {
            $px = $n === 1 ? $x + intdiv($w, 2) : (int) round($x + $i * $w / ($n - 1));
            $py = (int) round($y + $h - ($v - $min) / ($max - $min) * $h);
            $points[] = [$px, $py];
        }
        if ($n >= 2) {
            $poly = [];
            foreach ($points as [$px, $py]) {
                $poly[] = $px;
                $poly[] = $py;
            }
            $poly[] = $points[$n - 1][0];
            $poly[] = $y + $h;
            $poly[] = $points[0][0];
            $poly[] = $y + $h;
            imagefilledpolygon($this->im, $poly, $this->color($fillHex, 90));
            imagesetthickness($this->im, 4);
            for ($i = 1; $i < $n; $i++) {
                imageline($this->im, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $this->color($hex));
            }
            imagesetthickness($this->im, 1);
        }
        foreach ($points as $i => [$px, $py]) {
            $last = $i === $n - 1;
            imagefilledellipse($this->im, $px, $py, $last ? 16 : 10, $last ? 16 : 10, $this->color($hex));
            imagefilledellipse($this->im, $px, $py, $last ? 8 : 4, $last ? 8 : 4, $this->color('#ffffff'));
        }
    }

    /** Görseli (png/jpg/webp/gif) orantılı sığdırarak yerleştirir. */
    public function image(string $path, int $x, int $y, int $w, int $h): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if (! $src) {
            return false;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min($w / $sw, $h / $sh);
        $dw = (int) round($sw * $scale);
        $dh = (int) round($sh * $scale);
        imagecopyresampled($this->im, $src, $x + intdiv($w - $dw, 2), $y + intdiv($h - $dh, 2), 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);

        return true;
    }

    public function toPng(): string
    {
        ob_start();
        imagepng($this->im, null, 6);

        return (string) ob_get_clean();
    }

    public function save(string $path): void
    {
        @mkdir(dirname($path), 0775, true);
        imagepng($this->im, $path, 6);
    }

    public function __destruct()
    {
        imagedestroy($this->im);
    }

    private function measure(string $text, float $pt, string $font): int
    {
        $box = imagettfbbox($pt, 0, $font, $text);

        return $box ? (int) (max($box[2], $box[4]) - min($box[0], $box[6])) : 0;
    }

    private function truncate(string $text, float $pt, string $font, int $maxWidth): string
    {
        if ($this->measure($text, $pt, $font) <= $maxWidth) {
            return $text;
        }
        $len = mb_strlen($text);
        while ($len > 1) {
            $len--;
            $candidate = rtrim(mb_substr($text, 0, $len)).'…';
            if ($this->measure($candidate, $pt, $font) <= $maxWidth) {
                return $candidate;
            }
        }

        return '…';
    }

    /** GD, "&…;" dizilerini HTML varlığı sayar; ham & işaretini korur. */
    private function escape(string $text): string
    {
        return str_replace('&', '&amp;', $text);
    }
}
