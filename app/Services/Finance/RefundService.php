<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\ActivityFeed;
use App\Models\FinanceAccount;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Services\Accounting\AccountingPoster;
use App\Services\Accounting\Dec;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat iadesi (makbuzlu). Tahsilat satırı değişmez; iade ayrı belgedir.
 *  - Önce tahsilatın dağıtılmamış (fazla ödeme/avans) kısmından düşülür; kalan taksitlerden geri alınır
 *    (en geç vadeli taksitten başlayarak) → o taksitlerin borcu yeniden açılır.
 *  - Kayıt iptalinde kalan borcu kapatmak için Kayıt ve Planlar ekranındaki indirim/plan düzenlemesi kullanılır.
 *  - Muhasebe: faturasız kısım 340'tan, faturalı kısım 120'den düşülür; kasa/banka alacaklandırılır.
 */
class RefundService
{
    public function __construct(private readonly Ledger $ledger, private readonly AccountingPoster $poster) {}

    /** Tahsilattan iade edilebilecek en yüksek tutar. */
    public static function refundable(Payment $payment): string
    {
        if ($payment->voided_at) {
            return '0.00';
        }
        $refunded = Money::of((string) DB::table('refunds')->where('payment_id', $payment->id)->whereNull('voided_at')->sum('amount'));
        $left = bcsub((string) $payment->amount, $refunded, 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    /**
     * @param array{amount:string, finance_account_id:int, method:string, refunded_at?:?string, reason:string,
     *   payee_name?:?string, reference?:?string, idempotency_key?:?string} $data
     */
    public function refund(Payment $payment, array $data): Refund
    {
        $amount = Money::of($data['amount']);
        if (! Money::isPositive($amount)) {
            throw new BusinessRuleException('İade tutarı sıfırdan büyük olmalı.', 'invalid_amount');
        }
        if (mb_strlen(trim($data['reason'] ?? '')) < 5) {
            throw new BusinessRuleException('İade gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }
        if (! array_key_exists($data['method'], Payment::METHODS)) {
            throw new BusinessRuleException('Geçersiz iade yöntemi.', 'invalid_method');
        }
        if (! empty($data['idempotency_key']) && ($existing = Refund::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }
        $refundedAt = CarbonImmutable::parse($data['refunded_at'] ?? now());
        if ($refundedAt->gt(CarbonImmutable::now()->addMinutes(10))) {
            throw new BusinessRuleException('İade tarihi ileri bir zaman olamaz.', 'future_date');
        }

        return DB::transaction(function () use ($payment, $data, $amount, $refundedAt) {
            /** @var Payment $pay */
            $pay = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($pay->voided_at) {
                throw new BusinessRuleException('İptal edilmiş tahsilattan iade yapılamaz.', 'payment_voided');
            }
            if ($refundedAt->lt(CarbonImmutable::parse($pay->paid_at)->startOfDay())) {
                throw new BusinessRuleException('İade tarihi tahsilat tarihinden önce olamaz.', 'invalid_date');
            }
            $account = FinanceAccount::query()->findOrFail($data['finance_account_id']);
            if (! $account->is_active) {
                throw new BusinessRuleException("\"{$account->name}\" hesabı pasif durumda.", 'account_inactive');
            }
            $refundable = self::refundable($pay);
            if (bccomp($amount, $refundable, 2) > 0) {
                throw new BusinessRuleException(sprintf('Bu tahsilattan en çok %s TL iade edilebilir.', Money::format($refundable)), 'refund_exceeds_payment', ['refundable' => $refundable]);
            }

            $fromCredit = Dec::min($amount, PaymentService::unallocated($pay));
            $fromInstallments = bcsub($amount, $fromCredit, 2);

            // Faturalı / faturasız ayrımı (önce faturasız kısım iade edilir)
            $issuedLinked = Money::of((string) DB::table('invoice_payments as ip')->join('invoices as i', 'i.id', '=', 'ip.invoice_id')
                ->where('ip.payment_id', $pay->id)->where('i.status', 'issued')->where('i.kind', 'sales')->sum('ip.amount'));
            $prevUnbilled = Money::of((string) DB::table('refunds')->where('payment_id', $pay->id)->whereNull('voided_at')->selectRaw('COALESCE(SUM(amount - invoiced_portion), 0) AS s')->value('s'));
            $unbilledLeft = Dec::max(bcsub(bcsub((string) $pay->amount, $issuedLinked, 2), $prevUnbilled, 2), '0');
            $invoicedPortion = Dec::max(bcsub($amount, $unbilledLeft, 2), '0');

            $refund = Refund::query()->create([
                'refund_no' => Sequence::next('refund', Settings::get('finance.refund_prefix', 'IAD')),
                'student_id' => $pay->student_id,
                'payment_id' => $pay->id,
                'finance_account_id' => $account->id,
                'method' => $data['method'],
                'amount' => $amount,
                'from_credit' => $fromCredit,
                'from_installments' => $fromInstallments,
                'invoiced_portion' => $invoicedPortion,
                'refunded_at' => $refundedAt,
                'reason' => mb_substr(trim($data['reason']), 0, 300),
                'payee_name' => $data['payee_name'] ?? $pay->payer_name,
                'reference' => $data['reference'] ?? null,
                'created_by' => Auth::id(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            if (Money::isPositive($fromInstallments)) {
                $this->reverseAllocations($pay, $refund, $fromInstallments);
            }

            $student = DB::table('students')->where('id', $pay->student_id)->value('full_name');
            $this->ledger->post($account->id, bcmul($amount, '-1', 2), $refund, "İade {$refund->refund_no} — {$student} ({$pay->receipt_no})", $refundedAt);

            if (AccountingPoster::enabled($refund->branch_id)) {
                $this->poster->refund($refund);
            }

            ActivityFeed::query()->create([
                'kind' => 'payment',
                'message' => sprintf('%s için %s TL iade yapıldı', $student, Money::format($amount)),
                'subject_type' => $refund->getMorphClass(),
                'subject_id' => $refund->id,
                'student_id' => $pay->student_id,
                'meta' => ['amount' => $amount, 'refund' => true],
                'occurred_at' => $refundedAt,
            ]);

            Audit::log('refund.created', sprintf('%s makbuzundan %s TL iade etti (%s, iade no %s). Taksitlerden %s TL geri alındı. Gerekçe: %s',
                $pay->receipt_no, Money::format($amount), Payment::METHODS[$refund->method], $refund->refund_no, Money::format($fromInstallments), $refund->reason), $refund);

            return $refund;
        });
    }

    public function void(Refund $refund, string $reason): Refund
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('İptal gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }

        return DB::transaction(function () use ($refund, $reason) {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at) {
                throw new BusinessRuleException('Bu iade zaten iptal edilmiş.', 'already_voided');
            }
            foreach ($locked->allocations()->get() as $a) {
                /** @var Installment $inst */
                $inst = Installment::query()->whereKey($a->installment_id)->lockForUpdate()->firstOrFail();
                $paid = Dec::min(bcadd((string) $inst->paid_amount, (string) $a->amount, 2), (string) $inst->amount);
                $inst->forceFill([
                    'paid_amount' => $paid,
                    'status' => $inst->status === 'cancelled' ? 'cancelled' : PaymentService::statusFor($inst->amount, $paid, $inst->due_date),
                    'paid_at' => bccomp($paid, (string) $inst->amount, 2) >= 0 ? now() : null,
                ])->save();
            }
            $this->ledger->post($locked->finance_account_id, (string) $locked->amount, $locked, "İptal: iade {$locked->refund_no}", now());
            $locked->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => mb_substr(trim($reason), 0, 300)])->save();
            if (AccountingPoster::enabled($locked->branch_id)) {
                $this->poster->refundVoid($locked);
            }
            Audit::log('refund.voided', sprintf('%s numaralı %s TL iadeyi iptal etti; tutar hesaba geri alındı. Gerekçe: %s', $locked->refund_no, Money::format($locked->amount), $reason), $locked);

            return $locked;
        });
    }

    /** En geç vadeli taksitten başlayarak tahsilatın dağıtımını geri alır. */
    private function reverseAllocations(Payment $payment, Refund $refund, string $amount): void
    {
        $allocated = DB::table('payment_allocations')->where('payment_id', $payment->id)
            ->groupBy('installment_id')->selectRaw('installment_id, SUM(amount) AS amount')->pluck('amount', 'installment_id');
        $alreadyBack = DB::table('refund_allocations as ra')->join('refunds as r', 'r.id', '=', 'ra.refund_id')
            ->where('r.payment_id', $payment->id)->whereNull('r.voided_at')
            ->groupBy('ra.installment_id')->selectRaw('ra.installment_id, SUM(ra.amount) AS amount')->pluck('amount', 'installment_id');

        $installments = Installment::query()->whereIn('id', $allocated->keys())->orderByDesc('due_date')->orderByDesc('sequence')->lockForUpdate()->get();
        $left = $amount;
        foreach ($installments as $inst) {
            if (! Money::isPositive($left)) {
                break;
            }
            $avail = bcsub(Money::of((string) $allocated[$inst->id]), Money::of((string) ($alreadyBack[$inst->id] ?? '0')), 2);
            $avail = Dec::min($avail, (string) $inst->paid_amount);
            if (! Money::isPositive($avail)) {
                continue;
            }
            $portion = Money::min($left, $avail);
            RefundAllocation::query()->create(['refund_id' => $refund->id, 'installment_id' => $inst->id, 'amount' => $portion]);
            $paid = bcsub((string) $inst->paid_amount, $portion, 2);
            $inst->forceFill([
                'paid_amount' => $paid,
                'status' => $inst->status === 'cancelled' ? 'cancelled' : PaymentService::statusFor($inst->amount, $paid, $inst->due_date),
                'paid_at' => null,
            ])->save();
            $left = bcsub($left, $portion, 2);
        }
        if (Money::isPositive($left)) {
            throw new BusinessRuleException('İade tutarı tahsilatın taksit dağıtımını aşıyor.', 'refund_allocation_mismatch');
        }
    }
}
