<?php

namespace App\Services\Accounting;

use App\Support\Settings;

/**
 * Muhasebe / fatura / POS ayarları (ayar grubu "accounting"). Varsayılanlar burada; kurum ekrandan değiştirir.
 * KDV oranları koda gömülü değildir: `vat_rates` listesi ve `default_vat_rate` ayardan gelir.
 * UYARI: Varsayılan oranlar yalnız başlangıç önerisidir; kurum muhasebecisiyle teyit edilmelidir.
 */
final class FinanceSettings
{
    public const DEFAULTS = [
        'auto_journal' => true,
        'invoice_prefix' => 'EBE',
        'return_prefix' => 'EBI',
        'default_document_type' => 'e_archive',
        'vat_rates' => ['0', '1', '10', '20'],
        'default_vat_rate' => '10',
        'prices_include_vat' => true,
        'withholding_enabled' => false,
        'default_unit' => 'ADET',
        'service_description' => 'Eğitim hizmeti bedeli',
        'invoice_note' => null,
        'integrator' => 'none',          // none | simulation
        'pos_commission_rate' => '0',    // yüzde
        'pos_settlement_days' => 1,      // iş günü
        // Senet (bono) — boşsa kurum adı/adresi kullanılır. Metin hukukçu teyidine tabidir.
        'note_payee' => null,
        'note_place' => null,
        'note_court' => 'Erbaa',
        'note_consideration' => 'eğitim hizmeti karşılığı',
        'note_acceleration' => true,
    ];

    public static function all(?int $branchId = null): array
    {
        $values = array_merge(self::DEFAULTS, Settings::group('accounting', $branchId));
        $values['vat_rates'] = array_values(array_unique(array_map(fn ($r) => Dec::round((string) $r, 2) === '0.00' ? '0' : rtrim(rtrim(Dec::round((string) $r, 2), '0'), '.'), (array) $values['vat_rates'])));

        return $values;
    }

    public static function get(string $key, ?int $branchId = null): mixed
    {
        return self::all($branchId)[$key] ?? null;
    }
}
