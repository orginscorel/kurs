<?php

namespace Tests\Unit;

use App\Models\MessageTemplate;
use PHPUnit\Framework\TestCase;

class MessagingTemplateTest extends TestCase
{
    public function test_render_replaces_known_variables(): void
    {
        $template = new MessageTemplate(['body' => "Sayın Velimiz, {{ogrenci_adi}} saat {{saat}}'de kuruma giriş yaptı."]);

        $this->assertSame(
            "Sayın Velimiz, Ahmet Yılmaz saat 08:15'de kuruma giriş yaptı.",
            $template->render(['ogrenci_adi' => 'Ahmet Yılmaz', 'saat' => '08:15']),
        );
    }

    public function test_render_leaves_unknown_variables_blank_not_broken(): void
    {
        $template = new MessageTemplate(['body' => 'Merhaba {{ogrenci_adi}}, {{bilinmeyen}} değişkeni boş kalır.']);

        // Bilinmeyen değişken boş metinle değiştirilir (yer tutucu kaybolur, çevresindeki boşluk kalır).
        $this->assertSame('Merhaba Ayşe,  değişkeni boş kalır.', $template->render(['ogrenci_adi' => 'Ayşe']));
    }

    public function test_render_tolerates_surrounding_whitespace_inside_placeholder(): void
    {
        $template = new MessageTemplate(['body' => 'Tutar: {{ tutar }} TL']);

        $this->assertSame('Tutar: 1.250,00 TL', $template->render(['tutar' => '1.250,00']));
    }
}
