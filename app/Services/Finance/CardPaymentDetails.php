<?php

namespace App\Services\Finance;

use App\Models\Payment;
use App\Models\PaymentCardDetail;
use App\Services\Accounting\Dec;
use App\Services\Accounting\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kart / POS tahsilatlarında komisyon, net tutar ve beklenen banka yatış tarihi.
 * Tahsilat satırı değişmez; ayrıntı yan tabloya yazılır. Komisyon oranı ve valör günü ayardan gelir;
 * gerçek komisyon POS yatışı eşleştirilirken (mutabakat) kesinleşir.
 */
class CardPaymentDetails
{
    public const CARD_METHODS = ['credit_card', 'pos'];

    public function isCard(Payment $p, ?string $accountKind = null): bool
    {
        $accountKind ??= DB::table('finance_accounts')->where('id', $p->finance_account_id)->value('kind');

        return $accountKind === 'pos' || in_array($p->method, self::CARD_METHODS, true);
    }

    public function capture(Payment $p, ?string $rate = null, int $cardInstallments = 1): ?PaymentCardDetail
    {
        if (! $this->isCard($p) || $p->voided_at !== null) {
            return null;
        }
        if ($existing = PaymentCardDetail::query()->withoutGlobalScopes()->where('payment_id', $p->id)->first()) {
            return $existing;
        }
        $settings = FinanceSettings::all($p->branch_id);
        $rate = Dec::round($rate ?? (string) $settings['pos_commission_rate'], 3);
        [$commission, $net] = self::split((string) $p->amount, $rate);

        return PaymentCardDetail::query()->create([
            'branch_id' => $p->branch_id,
            'payment_id' => $p->id,
            'commission_rate' => $rate,
            'commission_amount' => $commission,
            'net_amount' => $net,
            'expected_deposit_date' => self::expectedDate(CarbonImmutable::parse($p->paid_at), (int) $settings['pos_settlement_days'])->toDateString(),
            'card_installments' => max(1, min(12, $cardInstallments)),
        ]);
    }

    /** Tahsilat ekranında girilen oran / taksit sayısı (yatış eşleşmeden önce). */
    public function override(Payment $p, ?string $rate, ?int $cardInstallments): void
    {
        $detail = PaymentCardDetail::query()->where('payment_id', $p->id)->whereNull('pos_settlement_id')->first();
        if (! $detail) {
            return;
        }
        if ($rate !== null) {
            $rate = Dec::round($rate, 3);
            [$commission, $net] = self::split((string) $p->amount, $rate);
            $detail->forceFill(['commission_rate' => $rate, 'commission_amount' => $commission, 'net_amount' => $net]);
        }
        if ($cardInstallments !== null) {
            $detail->card_installments = max(1, min(12, $cardInstallments));
        }
        $detail->save();
    }

    /** @return array{0:string, 1:string} komisyon, net (kuruşa yuvarlı; komisyon + net = brüt) */
    public static function split(string $gross, string $ratePercent): array
    {
        $commission = Dec::round(Dec::div(Dec::mul($gross, $ratePercent), '100'));

        return [$commission, bcsub(Dec::round($gross), $commission, 2)];
    }

    /** Valör: işlem gününden sonra N iş günü (hafta sonu atlanır). */
    public static function expectedDate(CarbonImmutable $paidAt, int $businessDays): CarbonImmutable
    {
        $d = $paidAt->startOfDay();
        $left = max(0, $businessDays);
        while ($left > 0) {
            $d = $d->addDay();
            if (! $d->isWeekend()) {
                $left--;
            }
        }

        return $d;
    }
}
