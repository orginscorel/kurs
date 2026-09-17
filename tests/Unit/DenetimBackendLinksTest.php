<?php

namespace Tests\Unit;

use App\Listeners\Automation\OnStudentClassChanged;
use App\Models\AutomationRule;
use App\Models\Webhook;
use App\Services\Automation\AutomationDescriber;
use App\Services\Placement\ClassStructure;
use App\Support\InstitutionFormat;
use PHPUnit\Framework\TestCase;

/** Denetim 2026-09-16 arka uç bağlantıları (saf mantık). */
class DenetimBackendLinksTest extends TestCase
{
    public function test_class_change_triggers_only_for_individual_moves_into_a_class(): void
    {
        $this->assertTrue(OnStudentClassChanged::shouldTrigger('change', 12));
        $this->assertTrue(OnStudentClassChanged::shouldTrigger('swap', 12));
        $this->assertTrue(OnStudentClassChanged::shouldTrigger('promotion', 12));
        // toplu yerleştirme / demo dönüşümü / geri alma bildirim üretmez
        $this->assertFalse(OnStudentClassChanged::shouldTrigger('placement', 12));
        $this->assertFalse(OnStudentClassChanged::shouldTrigger('restructure', 12));
        $this->assertFalse(OnStudentClassChanged::shouldTrigger('revert', 12));
        // sınıftan çıkış (seviye atlatmada eski sınıfın kapanması, bekleme listesine düşme) bildirim üretmez
        $this->assertFalse(OnStudentClassChanged::shouldTrigger('promotion', null));
        $this->assertFalse(OnStudentClassChanged::shouldTrigger('change', null));
    }

    public function test_class_changed_trigger_is_wired_everywhere(): void
    {
        $this->assertArrayHasKey('student.class_changed', AutomationRule::TRIGGERS);
        $this->assertContains('student.class_changed', Webhook::EVENTS);
        $this->assertSame('Öğrencinin sınıfı değiştiğinde velisine WhatsApp gönder.',
            AutomationDescriber::describe('student.class_changed', null, [['type' => 'whatsapp', 'to' => 'guardian']]));
    }

    public function test_every_trigger_has_a_source_description(): void
    {
        foreach (array_keys(AutomationRule::TRIGGERS) as $t) {
            $this->assertArrayHasKey($t, AutomationRule::TRIGGER_SOURCES, "{$t} için kaynak açıklaması yok");
        }
    }

    public function test_empty_class_structure_has_no_assumed_levels(): void
    {
        $s = ClassStructure::emptyStructure(18);

        $this->assertSame([], $s['levels']);
        $this->assertSame(18, $s['default_capacity']);
        $this->assertSame('SAY', $s['track_defaults']['A']);
    }

    public function test_institution_format_helpers(): void
    {
        $this->assertSame('₺', InstitutionFormat::currencySymbol('TRY'));
        $this->assertSame('₺', InstitutionFormat::currencySymbol(null));
        $this->assertSame('€', InstitutionFormat::currencySymbol('eur'));
        $this->assertSame('CHF', InstitutionFormat::currencySymbol('CHF'));

        $this->assertSame('Europe/Istanbul', InstitutionFormat::validTimezone('Mars/Olympus'));
        $this->assertSame('Europe/Berlin', InstitutionFormat::validTimezone('Europe/Berlin'));

        $this->assertNull(InstitutionFormat::footerLine(['tax_office' => '', 'tax_number' => null, 'website' => null]));
        $this->assertSame('Erbaa V.D. · VKN 1234567890 · erbaabilgi.com',
            InstitutionFormat::footerLine(['tax_office' => 'Erbaa', 'tax_number' => '1234567890', 'website' => 'https://erbaabilgi.com/']));
        $this->assertSame('VKN 42', InstitutionFormat::taxLine(['tax_number' => ' 42 ']));
        $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', InstitutionFormat::stamp(['timezone' => 'Europe/Istanbul']));
    }
}
