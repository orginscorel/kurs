<?php

namespace Tests\Unit;

use App\Services\Finance\ContractTemplate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Sözleşme şablonu (saf mantık — veritabanı/uygulama gerektirmez).
 * Yer tutucu çözümü, ödeme planı tablosunun gömülmesi ve imza bloğunun imza durumuna göre üretimi.
 */
class ContractTemplateTest extends TestCase
{
    private function money(): callable
    {
        return fn ($v) => \App\Support\Money::format((string) $v).' ₺';
    }

    public function test_resolve_replaces_known_placeholders_and_keeps_unknown(): void
    {
        $out = ContractTemplate::resolve('Merhaba {ogrenci_ad}, {bilinmeyen} kalsın.', ['{ogrenci_ad}' => 'Ada Yılmaz']);

        $this->assertStringContainsString('Ada Yılmaz', $out);
        $this->assertStringContainsString('{bilinmeyen}', $out); // bilinmeyen yer tutucu olduğu gibi kalır
    }

    public function test_default_template_contains_key_placeholders(): void
    {
        $tpl = ContractTemplate::defaultTemplate();
        foreach (['{taksit_tablosu}', '{imza_bloklari}', '{kurum_adi}', '{kayit_no}', '{ucret_tablosu}'] as $ph) {
            $this->assertStringContainsString($ph, $tpl, "Varsayılan şablon {$ph} içermeli.");
        }
    }

    public function test_placeholders_list_is_not_empty_and_well_formed(): void
    {
        foreach (ContractTemplate::placeholders() as $p) {
            $this->assertArrayHasKey('key', $p);
            $this->assertArrayHasKey('label', $p);
            $this->assertMatchesRegularExpression('/^\{[a-z_]+\}$/', $p['key']);
        }
    }

    public function test_plan_table_embeds_installment_rows_and_total(): void
    {
        $rows = collect([
            (object) ['sequence' => 1, 'due_date' => CarbonImmutable::parse('2026-10-01'), 'amount' => '1000.00'],
            (object) ['sequence' => 2, 'due_date' => CarbonImmutable::parse('2026-11-01'), 'amount' => '1500.50'],
        ]);

        $html = ContractTemplate::planTable($rows, $this->money());

        $this->assertStringContainsString('01.10.2026', $html);
        $this->assertStringContainsString('11.2026', $html);
        $this->assertStringContainsString('Toplam', $html);
        $this->assertStringContainsString('2.500,50 ₺', $html); // 1000 + 1500,50
    }

    public function test_plan_table_empty_returns_notice(): void
    {
        $html = ContractTemplate::planTable(collect(), $this->money());
        $this->assertStringContainsString('ödeme planı bulunmamaktadır', $html);
    }

    public function test_signatures_hide_date_before_signing_and_show_after(): void
    {
        $draft = ContractTemplate::signatures('Erbaa Bilgi', '', null);
        $this->assertStringNotContainsString('İmza tarihi', $draft);

        $signed = ContractTemplate::signatures('Erbaa Bilgi', 'Veli Adı', CarbonImmutable::parse('2026-09-22 14:30'));
        $this->assertStringContainsString('İmza tarihi', $signed);
        $this->assertStringContainsString('Veli Adı', $signed);
        $this->assertStringContainsString('22.09.2026', $signed);
    }

    public function test_effective_falls_back_to_default_when_blank(): void
    {
        $this->assertSame(ContractTemplate::defaultTemplate(), ContractTemplate::effective(null));
        $this->assertSame(ContractTemplate::defaultTemplate(), ContractTemplate::effective('   '));
        $this->assertSame('Özel şablon', ContractTemplate::effective('Özel şablon'));
    }
}
