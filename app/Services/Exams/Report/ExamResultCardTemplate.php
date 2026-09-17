<?php

namespace App\Services\Exams\Report;

/**
 * Deneme sonuç kartı (WhatsApp'a gönderilecek 1080×1350 dikey PNG).
 *
 * Veri: institution{name,short_name,logo_path}, student{name,no,class}, exam{name,date,type,publisher,scope},
 *       sections[{name,code,correct,wrong,blank,net,question_count}], totals{correct,wrong,blank,net,score,question_count},
 *       ranks{institution,institution_total,class,class_total,national}, history[{label,net}], generated_at
 */
final class ExamResultCardTemplate implements ReportTemplate
{
    public function size(): array
    {
        return [1080, 1350];
    }

    public function draw(Canvas $c, array $d): void
    {
        $W = $c->width;
        $pad = 56;
        $inner = $W - $pad * 2;

        // ---------------------------------------------------------------- üst bant
        $c->rect(0, 0, $W, 250, Brand::PRIMARY);
        $c->circle($W - 30, 30, 420, '#6a60ea');   // sağ üstte dekoratif açık daire
        $c->circle($W - 30, 30, 300, '#7d73ff');
        $c->rect(0, 250, $W, 60, Brand::PRIMARY_DARK);

        $logoBox = 96;
        $hasLogo = ! empty($d['institution']['logo_path']) && $c->image($d['institution']['logo_path'], $pad, 60, $logoBox, $logoBox);
        if (! $hasLogo) {
            $c->roundedRect($pad, 60, $logoBox, $logoBox, 24, '#ffffff');
            $initial = mb_strtoupper(mb_substr($d['institution']['short_name'] ?: $d['institution']['name'], 0, 1));
            $c->text($pad + intdiv($logoBox, 2), 60 + 66, $initial, 52, Brand::PRIMARY, true, 'center');
        }
        $c->fitText($pad + $logoBox + 26, 108, $d['institution']['name'], 40, 26, '#ffffff', true, 'left', $inner - $logoBox - 26 - 220);
        $c->text($pad + $logoBox + 26, 150, 'Deneme Sınavı Sonuç Belgesi', 24, '#e6e3ff', false, 'left');
        $c->text($W - $pad, 96, $d['exam']['date'], 24, '#ffffff', true, 'right');
        $c->text($W - $pad, 130, $d['exam']['type'], 20, '#e6e3ff', false, 'right');

        // sınav adı bandı
        $c->fitText($pad, 292, $d['exam']['name'], 26, 18, '#ffffff', true, 'left', $inner - 200);
        if (! empty($d['exam']['publisher'])) {
            $c->text($W - $pad, 292, $d['exam']['publisher'], 20, '#cfcaff', false, 'right', 190);
        }

        // ---------------------------------------------------------------- öğrenci
        $y = 372;
        $c->fitText($pad, $y, $d['student']['name'], 46, 30, Brand::INK, true, 'left', $inner - 260);
        $meta = trim(($d['student']['no'] ? 'No '.$d['student']['no'] : '').($d['student']['class'] ? '  ·  '.$d['student']['class'] : ''), " ·");
        $c->text($pad, $y + 38, $meta, 22, Brand::INK_2, false, 'left');

        // kurum sırası rozeti (sağ)
        if (! empty($d['ranks']['institution'])) {
            $badgeW = 236;
            $c->roundedRect($W - $pad - $badgeW, $y - 44, $badgeW, 92, 20, Brand::PRIMARY_SOFT);
            $c->text($W - $pad - intdiv($badgeW, 2), $y - 12, 'KURUM SIRASI', 16, Brand::PRIMARY_DARK, true, 'center');
            $rankText = $d['ranks']['institution'].' / '.$d['ranks']['institution_total'];
            $c->text($W - $pad - intdiv($badgeW, 2), $y + 32, $rankText, 34, Brand::PRIMARY_DARK, true, 'center');
        }

        // ---------------------------------------------------------------- büyük sayılar
        $y = 450;
        $tiles = [
            ['TOPLAM NET', $this->num($d['totals']['net'], 2), Brand::INK],
            ['PUAN', $d['totals']['score'] !== null ? $this->num($d['totals']['score'], 2) : '—', Brand::INK],
            ['DOĞRU', (string) $d['totals']['correct'], Brand::SUCCESS],
            ['YANLIŞ', (string) $d['totals']['wrong'], Brand::DANGER],
            ['BOŞ', (string) $d['totals']['blank'], Brand::INK_3],
        ];
        $gap = 14;
        $tileW = intdiv($inner - $gap * (count($tiles) - 1), count($tiles));
        foreach ($tiles as $i => [$label, $value, $color]) {
            $x = $pad + $i * ($tileW + $gap);
            $c->roundedRect($x, $y, $tileW, 124, 18, Brand::SURFACE_2);
            $c->text($x + intdiv($tileW, 2), $y + 38, $label, 15, Brand::INK_3, true, 'center');
            $c->fitText($x + intdiv($tileW, 2), $y + 96, $value, $i < 2 ? 40 : 44, 24, $color, true, 'center', $tileW - 20);
        }

        // ---------------------------------------------------------------- ders netleri
        $y = 620;
        $c->text($pad, $y, 'Ders Netleri', 24, Brand::INK, true);
        $c->text($W - $pad, $y, 'D / Y / B', 17, Brand::INK_3, false, 'right');
        $y += 20;
        $rows = $d['sections'];
        $rowH = count($rows) > 5 ? 52 : 62;
        foreach ($rows as $i => $s) {
            $ry = $y + $i * $rowH;
            if ($i % 2 === 0) {
                $c->roundedRect($pad, $ry, $inner, $rowH, 12, Brand::SURFACE_2);
            }
            $base = $ry + intdiv($rowH, 2) + 8;
            $c->text($pad + 18, $base, $s['name'], 22, Brand::INK, true, 'left', 300);
            $pct = $s['question_count'] > 0 ? max(0, (float) $s['net']) / $s['question_count'] * 100 : 0;
            $c->bar($pad + 340, $ry + intdiv($rowH, 2) - 6, 300, 12, $pct, $pct >= 60 ? Brand::SUCCESS : ($pct >= 35 ? Brand::PRIMARY : Brand::WARNING), Brand::SURFACE_3);
            $c->text($pad + 660, $base, $this->num($s['net'], 2), 24, Brand::INK, true, 'left');
            $c->text($W - $pad - 18, $base, "{$s['correct']} / {$s['wrong']} / {$s['blank']}", 19, Brand::INK_2, false, 'right');
        }
        $y += count($rows) * $rowH + 36;

        // ---------------------------------------------------------------- sıralamalar + gelişim
        $colW = intdiv($inner - 20, 2);
        $boxH = 1350 - 96 - $y;
        $boxH = max(180, min($boxH, 300));

        // sol: sıralamalar
        $c->roundedRect($pad, $y, $colW, $boxH, 18, Brand::SURFACE_2);
        $c->text($pad + 24, $y + 40, 'Sıralamalar', 21, Brand::INK, true);
        $rankRows = [
            ['Kurum', $d['ranks']['institution'] ? $d['ranks']['institution'].' / '.$d['ranks']['institution_total'] : '—'],
            ['Sınıf', $d['ranks']['class'] ? $d['ranks']['class'].' / '.$d['ranks']['class_total'] : '—'],
            ['Genel (Türkiye)', $d['ranks']['national'] ? number_format((int) $d['ranks']['national'], 0, ',', '.') : ($d['exam']['scope'] === 'national' ? 'bekleniyor' : '—')],
        ];
        foreach ($rankRows as $i => [$label, $value]) {
            $ry = $y + 84 + $i * 46;
            $c->text($pad + 24, $ry, $label, 20, Brand::INK_2);
            $c->text($pad + $colW - 24, $ry, $value, 24, Brand::INK, true, 'right');
        }

        // sağ: net gelişimi
        $x2 = $pad + $colW + 20;
        $c->roundedRect($x2, $y, $colW, $boxH, 18, Brand::SURFACE_2);
        $c->text($x2 + 24, $y + 40, 'Net Gelişimi', 21, Brand::INK, true);
        $history = array_values($d['history'] ?? []);
        if (count($history) >= 2) {
            $nets = array_map(fn ($h) => (float) $h['net'], $history);
            $first = $nets[0];
            $last = $nets[count($nets) - 1];
            $delta = $last - $first;
            $deltaText = ($delta >= 0 ? '+' : '−').$this->num(abs($delta), 1).' net';
            $c->text($x2 + $colW - 24, $y + 40, $deltaText, 20, $delta >= 0 ? Brand::SUCCESS : Brand::DANGER, true, 'right');
            $c->sparkline($x2 + 32, $y + 70, $colW - 64, $boxH - 130, $nets, Brand::PRIMARY, Brand::PRIMARY);
            $c->text($x2 + 32, $y + $boxH - 22, $history[0]['label'], 15, Brand::INK_3);
            $c->text($x2 + $colW - 32, $y + $boxH - 22, $history[count($history) - 1]['label'], 15, Brand::INK_3, false, 'right');
        } else {
            $c->text($x2 + 24, $y + 96, 'Bu türde ilk deneme.', 19, Brand::INK_3);
            $c->text($x2 + 24, $y + 126, 'Sonraki sınavlarda gelişim grafiği burada görünür.', 16, Brand::INK_3, false, 'left', $colW - 48);
        }

        // ---------------------------------------------------------------- alt bilgi
        $c->line($pad, 1350 - 70, $W - $pad, 1350 - 70, Brand::LINE, 2);
        $c->text($pad, 1350 - 34, $d['institution']['name'], 18, Brand::INK_2, true);
        $c->text($W - $pad, 1350 - 34, 'Oluşturma: '.$d['generated_at'], 16, Brand::INK_3, false, 'right');
    }

    private function num(float|int|string|null $v, int $digits): string
    {
        return number_format((float) $v, $digits, ',', '.');
    }
}
