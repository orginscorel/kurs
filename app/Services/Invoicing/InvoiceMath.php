<?php

namespace App\Services\Invoicing;

use App\Exceptions\BusinessRuleException;
use App\Services\Accounting\Dec;

/**
 * Fatura kalem hesabı (saf fonksiyon, bcmath). Kurallar:
 *  brüt      = miktar × birim fiyat                         (kuruşa yuvarlanır)
 *  iskonto   = oran verilmişse brüt × oran / 100, yoksa tutar (kuruşa yuvarlanır, brütü aşamaz)
 *  KDV dahil fiyatta:  toplam = brüt − iskonto;  matrah = toplam / (1 + oran/100) (yuvarlanır);  KDV = toplam − matrah
 *  KDV hariç fiyatta:  matrah = brüt − iskonto;  KDV = matrah × oran / 100 (yuvarlanır);  toplam = matrah + KDV
 *  tevkifat  = KDV × (n / 10)                                (yuvarlanır)
 *  ödenecek  = toplam − tevkifat
 * Fatura toplamları kalem sonuçlarının toplamıdır (kalem bazında yuvarlama; toplamda ikinci yuvarlama yok).
 */
final class InvoiceMath
{
    /**
     * @param array{quantity:string, unit_price:string, discount_rate?:?string, discount_amount?:?string, vat_rate:string, withholding_tenths?:?int} $line
     * @return array{quantity:string, unit_price:string, discount_rate:string, discount_amount:string, vat_rate:string, withholding_tenths:int, gross_amount:string, net_amount:string, vat_amount:string, withholding_amount:string, total_amount:string}
     */
    public static function line(array $line, bool $pricesIncludeVat): array
    {
        $qty = bcadd(Dec::norm($line['quantity'] ?? '1'), '0', 3);
        $price = Dec::round(Dec::norm($line['unit_price'] ?? '0'));
        $rate = Dec::round(Dec::norm($line['vat_rate'] ?? '0'));
        $discRate = Dec::round(Dec::norm($line['discount_rate'] ?? '0'));
        $tenths = (int) ($line['withholding_tenths'] ?? 0);

        if (bccomp($qty, '0', 3) <= 0) {
            throw new BusinessRuleException('Kalem miktarı sıfırdan büyük olmalı.', 'invalid_quantity');
        }
        if (bccomp($price, '0', 2) < 0) {
            throw new BusinessRuleException('Birim fiyat negatif olamaz.', 'invalid_price');
        }
        if (bccomp($rate, '0', 2) < 0 || bccomp($rate, '100', 2) > 0) {
            throw new BusinessRuleException('KDV oranı 0-100 arasında olmalı.', 'invalid_vat');
        }
        if (bccomp($discRate, '0', 2) < 0 || bccomp($discRate, '100', 2) > 0) {
            throw new BusinessRuleException('İskonto oranı 0-100 arasında olmalı.', 'invalid_discount');
        }
        if ($tenths < 0 || $tenths > 10) {
            throw new BusinessRuleException('Tevkifat oranı 0/10 ile 10/10 arasında olmalı.', 'invalid_withholding');
        }

        $gross = Dec::round(Dec::mul($qty, $price));
        $discount = Dec::positive($discRate)
            ? Dec::round(Dec::div(Dec::mul($gross, $discRate), '100'))
            : Dec::round(Dec::norm($line['discount_amount'] ?? '0'));
        if (bccomp($discount, '0', 2) < 0 || bccomp($discount, $gross, 2) > 0) {
            throw new BusinessRuleException('İskonto kalem tutarını aşamaz.', 'invalid_discount');
        }
        $afterDiscount = bcsub($gross, $discount, 2);

        if ($pricesIncludeVat) {
            $total = $afterDiscount;
            $net = Dec::round(Dec::div($total, bcadd('1', Dec::div($rate, '100'), Dec::WORK_SCALE)));
            $vat = bcsub($total, $net, 2);
        } else {
            $net = $afterDiscount;
            $vat = Dec::round(Dec::div(Dec::mul($net, $rate), '100'));
            $total = bcadd($net, $vat, 2);
        }
        $withholding = Dec::round(Dec::div(Dec::mul($vat, (string) $tenths), '10'));

        return [
            'quantity' => $qty, 'unit_price' => $price, 'discount_rate' => $discRate, 'discount_amount' => $discount,
            'vat_rate' => $rate, 'withholding_tenths' => $tenths, 'gross_amount' => $gross, 'net_amount' => $net,
            'vat_amount' => $vat, 'withholding_amount' => $withholding, 'total_amount' => $total,
        ];
    }

    /**
     * @param list<array> $lines hesaplanmış kalemler
     * @return array{gross_total:string, discount_total:string, net_total:string, vat_total:string, withholding_total:string, grand_total:string, payable_total:string, vat_breakdown: list<array{rate:string, net:string, vat:string}>}
     */
    public static function totals(array $lines): array
    {
        $t = ['gross_total' => '0.00', 'discount_total' => '0.00', 'net_total' => '0.00', 'vat_total' => '0.00', 'withholding_total' => '0.00', 'grand_total' => '0.00'];
        $byRate = [];
        foreach ($lines as $l) {
            $t['gross_total'] = bcadd($t['gross_total'], $l['gross_amount'], 2);
            $t['discount_total'] = bcadd($t['discount_total'], $l['discount_amount'], 2);
            $t['net_total'] = bcadd($t['net_total'], $l['net_amount'], 2);
            $t['vat_total'] = bcadd($t['vat_total'], $l['vat_amount'], 2);
            $t['withholding_total'] = bcadd($t['withholding_total'], $l['withholding_amount'], 2);
            $t['grand_total'] = bcadd($t['grand_total'], $l['total_amount'], 2);
            $k = (string) $l['vat_rate'];
            $byRate[$k] ??= ['rate' => $k, 'net' => '0.00', 'vat' => '0.00'];
            $byRate[$k]['net'] = bcadd($byRate[$k]['net'], $l['net_amount'], 2);
            $byRate[$k]['vat'] = bcadd($byRate[$k]['vat'], $l['vat_amount'], 2);
        }
        $t['payable_total'] = bcsub($t['grand_total'], $t['withholding_total'], 2);
        ksort($byRate, SORT_NATURAL);
        $t['vat_breakdown'] = array_values($byRate);

        return $t;
    }

    /** KDV dahil tutardan tek kalem (tahsilat faturası): birim fiyat = tutar. */
    public static function singleLineFromTotal(string $total, string $vatRate, string $description, string $unit = 'ADET'): array
    {
        return ['description' => $description, 'quantity' => '1', 'unit' => $unit, 'unit_price' => Dec::round($total), 'discount_rate' => '0', 'discount_amount' => '0', 'vat_rate' => $vatRate, 'withholding_tenths' => 0];
    }
}
