<?php

namespace App\Services\Finance;

use App\Events\PaymentReceived;
use App\Events\PaymentVoided;
use App\Exceptions\BusinessRuleException;
use App\Models\ActivityFeed;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Student;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat. Payment + PaymentAllocation + Installment + AccountTransaction + makbuz no
 * tek transaction'da yazılır; biri başarısız olursa hiçbiri kalmaz.
 */
class PaymentService
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @param array{
     *   finance_account_id:int, method:string, amount:string|float, paid_at?:?string,
     *   enrollment_id?:?int, guardian_id?:?int, installment_ids?:?list<int>,
     *   reference?:?string, note?:?string, payer_name?:?string, idempotency_key?:?string,
     *   overpayment?:'reject'|'next'|'credit'
     * } $data
     */
    public function collect(Student $student, array $data): Payment
    {
        $amount = Money::of($data['amount']);

        if (! Money::isPositive($amount)) {
            throw new BusinessRuleException('Tahsilat tutarı sıfırdan büyük olmalı.', 'invalid_amount');
        }
        if (! array_key_exists($data['method'], Payment::METHODS)) {
            throw new BusinessRuleException('Geçersiz ödeme yöntemi.', 'invalid_method');
        }

        // Aynı istek ikinci kez gelirse (çift tıklama / ağ tekrarı) ilk kayıt döner.
        if (! empty($data['idempotency_key'])) {
            $existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing->load('allocations.installment');
            }
        }

        $paidAt = CarbonImmutable::parse($data['paid_at'] ?? now());

        return DB::transaction(function () use ($student, $data, $amount, $paidAt) {
            $query = Installment::query()
                ->where('student_id', $student->id)
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->orderBy('due_date')->orderBy('sequence')
                ->lockForUpdate();

            if (! empty($data['enrollment_id'])) {
                $query->where('enrollment_id', $data['enrollment_id']);
            }
            if (! empty($data['installment_ids'])) {
                $query->whereIn('id', $data['installment_ids']);
            }

            $installments = $query->get();
            $open = $installments->reduce(fn ($sum, Installment $i) => bcadd($sum, $i->remaining(), 2), '0.00');

            // Fazla ödeme: 'next' → seçili taksitlerden artan tutar öğrencinin sonraki açık taksitlerine aktarılır;
            // 'credit' → sonraki taksitlere aktarılır, yine artarsa öğrenci avansı (dağıtılmamış) olarak kalır.
            $overpayment = $data['overpayment'] ?? 'reject';
            if (bccomp($amount, $open, 2) > 0 && in_array($overpayment, ['next', 'credit'], true)) {
                $more = Installment::query()
                    ->where('student_id', $student->id)
                    ->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->whereNotIn('id', $installments->pluck('id'))
                    ->orderBy('due_date')->orderBy('sequence')
                    ->lockForUpdate()->get();
                $installments = $installments->concat($more)->values();
                $open = $installments->reduce(fn ($sum, Installment $i) => bcadd($sum, $i->remaining(), 2), '0.00');
            }

            if (bccomp($amount, $open, 2) > 0 && $overpayment !== 'credit') {
                throw new BusinessRuleException(
                    sprintf('Tahsilat tutarı açık bakiyeden (%s TL) fazla olamaz.', Money::format($open)),
                    'amount_exceeds_balance',
                    ['open_balance' => $open],
                );
            }

            $payment = Payment::query()->create([
                'receipt_no' => Sequence::next('receipt', Settings::get('finance.receipt_prefix', 'MKB')),
                'student_id' => $student->id,
                'enrollment_id' => $data['enrollment_id'] ?? $installments->first()?->enrollment_id,
                'guardian_id' => $data['guardian_id'] ?? null,
                'finance_account_id' => $data['finance_account_id'],
                'method' => $data['method'],
                'amount' => $amount,
                'paid_at' => $paidAt,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'payer_name' => $data['payer_name'] ?? null,
                'received_by' => Auth::id(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            // En eski vadeden başlayarak dağıt.
            $left = $amount;
            foreach ($installments as $installment) {
                if (! Money::isPositive($left)) {
                    break;
                }

                $portion = Money::min($left, $installment->remaining());
                $payment->allocations()->create(['installment_id' => $installment->id, 'amount' => $portion]);

                $paid = bcadd((string) $installment->paid_amount, $portion, 2);
                $installment->forceFill([
                    'paid_amount' => $paid,
                    'status' => self::statusFor($installment->amount, $paid, $installment->due_date),
                    'paid_at' => bccomp($paid, (string) $installment->amount, 2) >= 0 ? $paidAt : null,
                ])->save();

                $left = bcsub($left, $portion, 2);
            }

            $this->ledger->post(
                (int) $data['finance_account_id'],
                $amount,
                $payment,
                "Tahsilat {$payment->receipt_no} — {$student->full_name}",
                $paidAt,
            );

            ActivityFeed::query()->create([
                'kind' => 'payment',
                'message' => sprintf('%s %s TL ödeme yaptı', $student->full_name, Money::format($amount)),
                'subject_type' => $payment->getMorphClass(),
                'subject_id' => $payment->id,
                'student_id' => $student->id,
                'meta' => ['amount' => $amount, 'method' => $payment->method],
                'occurred_at' => $paidAt,
            ]);

            Audit::log('payment.created', sprintf(
                '%s öğrencisine %s TL tahsilat kaydetti (%s, makbuz %s).',
                $student->full_name, Money::format($amount), Payment::METHODS[$payment->method], $payment->receipt_no,
            ), $payment);

            DB::afterCommit(fn () => event(new PaymentReceived($payment->id)));

            return $payment->load('allocations.installment');
        });
    }

    /**
     * İptal: tahsilat silinmez. Dağıtımlar geri alınır, hesaba ters kayıt atılır.
     */
    public function void(Payment $payment, string $reason): Payment
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('İptal gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }

        return DB::transaction(function () use ($payment, $reason) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new BusinessRuleException('Bu tahsilat zaten iptal edilmiş.', 'already_voided');
            }
            if (DB::table('refunds')->where('payment_id', $locked->id)->whereNull('voided_at')->exists()) {
                throw new BusinessRuleException('Bu tahsilattan iade yapılmış; önce iadeyi iptal edin.', 'payment_has_refunds');
            }
            if (DB::table('invoice_payments as ip')->join('invoices as inv', 'inv.id', '=', 'ip.invoice_id')
                ->where('ip.payment_id', $locked->id)->where('inv.status', 'issued')->exists()) {
                throw new BusinessRuleException('Bu tahsilat kesilmiş bir faturaya bağlı; önce faturayı iptal edin ya da iade faturası düzenleyin.', 'payment_invoiced');
            }

            foreach ($locked->allocations()->get() as $allocation) {
                /** @var Installment $installment */
                $installment = Installment::query()->whereKey($allocation->installment_id)->lockForUpdate()->firstOrFail();
                $paid = bcsub((string) $installment->paid_amount, (string) $allocation->amount, 2);
                $paid = bccomp($paid, '0', 2) < 0 ? '0.00' : $paid;

                $installment->forceFill([
                    'paid_amount' => $paid,
                    'status' => self::statusFor($installment->amount, $paid, $installment->due_date),
                    'paid_at' => null,
                ])->save();
            }

            $this->ledger->post(
                $locked->finance_account_id,
                bcmul((string) $locked->amount, '-1', 2),
                $locked,
                "İptal: tahsilat {$locked->receipt_no}",
                now(),
            );

            $locked->forceFill([
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => mb_substr($reason, 0, 300),
            ])->save();

            Audit::log('payment.voided', sprintf(
                '%s numaralı %s TL tahsilatı iptal etti. Gerekçe: %s',
                $locked->receipt_no, Money::format($locked->amount), $reason,
            ), $locked);

            DB::afterCommit(fn () => event(new PaymentVoided($locked->id)));

            return $locked;
        });
    }

    /**
     * Avans mahsubu: tahsilatın dağıtılmamış (fazla ödeme) kısmını öğrencinin açık taksitlerine uygular.
     * Yeni para girişi yoktur; hesap bakiyesi ve yevmiye değişmez (tahsilat zaten 340'ta).
     *
     * @return string uygulanan tutar
     */
    public function applyCredit(Payment $payment, ?array $installmentIds = null): string
    {
        return DB::transaction(function () use ($payment, $installmentIds) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw new BusinessRuleException('İptal edilmiş tahsilatın avansı kullanılamaz.', 'already_voided');
            }
            $credit = self::unallocated($locked);
            if (! Money::isPositive($credit)) {
                throw new BusinessRuleException('Bu tahsilatta kullanılabilir avans yok.', 'no_credit');
            }

            $installments = Installment::query()->where('student_id', $locked->student_id)
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->when($installmentIds, fn ($q) => $q->whereIn('id', $installmentIds))
                ->orderBy('due_date')->orderBy('sequence')->lockForUpdate()->get();
            if ($installments->isEmpty()) {
                throw new BusinessRuleException('Mahsup edilecek açık taksit yok.', 'no_open_installments');
            }

            $left = $credit;
            $applied = '0.00';
            foreach ($installments as $installment) {
                if (! Money::isPositive($left)) {
                    break;
                }
                $portion = Money::min($left, $installment->remaining());
                $locked->allocations()->create(['installment_id' => $installment->id, 'amount' => $portion]);
                $paid = bcadd((string) $installment->paid_amount, $portion, 2);
                $installment->forceFill([
                    'paid_amount' => $paid,
                    'status' => self::statusFor($installment->amount, $paid, $installment->due_date),
                    'paid_at' => bccomp($paid, (string) $installment->amount, 2) >= 0 ? now() : null,
                ])->save();
                $left = bcsub($left, $portion, 2);
                $applied = bcadd($applied, $portion, 2);
            }

            Audit::log('payment.credit_applied', sprintf('%s numaralı tahsilatın %s TL avansını açık taksitlere mahsup etti.', $locked->receipt_no, Money::format($applied)), $locked);

            return $applied;
        });
    }

    /** Tahsilatın taksitlere dağıtılmamış ve iade edilmemiş kısmı (öğrenci avansı). */
    public static function unallocated(Payment $payment): string
    {
        $allocated = (string) DB::table('payment_allocations')->where('payment_id', $payment->id)->sum('amount');
        $refundedCredit = (string) DB::table('refunds')->where('payment_id', $payment->id)->whereNull('voided_at')->sum('from_credit');
        $left = bcsub(bcsub((string) $payment->amount, Money::of($allocated), 2), Money::of($refundedCredit), 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    public static function statusFor(string|float $amount, string $paid, \DateTimeInterface $dueDate): string
    {
        if (bccomp($paid, (string) $amount, 2) >= 0) {
            return 'paid';
        }

        $isOverdue = CarbonImmutable::instance($dueDate)->startOfDay()->lt(CarbonImmutable::today());

        if ($isOverdue) {
            return 'overdue';
        }

        return bccomp($paid, '0', 2) > 0 ? 'partial' : 'pending';
    }
}
