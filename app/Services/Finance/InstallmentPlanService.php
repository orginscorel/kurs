<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\Enrollment;
use App\Models\Installment;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ödeme planı değişiklikleri: yeniden yapılandırma ve sonradan indirim/burs.
 * Kurallar InstallmentPlanRules'ta; bu sınıf kilitler, uygular ve denetim kaydı yazar.
 * Tahsilat (PaymentService::collect) aynı taksit satırlarını kilitlediği için eşzamanlı çalışamazlar.
 */
class InstallmentPlanService
{
    /**
     * @param list<array{id?:int|null, due_date:string, amount:string}> $rows
     */
    public function restructure(Enrollment $enrollment, array $rows, ?string $note = null): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $rows, $note) {
            /** @var Enrollment $locked */
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $installments = $this->lockInstallments($locked);
            $before = $this->snapshot($installments);

            $plan = InstallmentPlanRules::restructure((string) $locked->net_price, $this->rulesInput($installments), $rows);

            foreach ($plan['update'] as $id => $change) {
                /** @var Installment $inst */
                $inst = $installments->firstWhere('id', $id);
                $paid = (string) $inst->paid_amount;
                $isPaid = bccomp($paid, $change['amount'], 2) >= 0;
                $inst->forceFill([
                    'amount' => $change['amount'],
                    'due_date' => $change['due_date'],
                    'status' => PaymentService::statusFor($change['amount'], $paid, CarbonImmutable::parse($change['due_date'])),
                    'paid_at' => $isPaid ? ($inst->paid_at ?? now()) : null,
                ])->save();
            }

            if ($plan['delete']) {
                Installment::query()->whereIn('id', $plan['delete'])->where('enrollment_id', $locked->id)->where('paid_amount', 0)->delete();
            }
            if ($plan['cancel']) {
                Installment::query()->whereIn('id', $plan['cancel'])->where('enrollment_id', $locked->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            $nextSeq = $this->maxSequence($locked) + 1;
            foreach ($plan['create'] as $new) {
                Installment::query()->create([
                    'branch_id' => $locked->branch_id,
                    'enrollment_id' => $locked->id,
                    'student_id' => $locked->student_id,
                    'sequence' => $nextSeq++,
                    'due_date' => $new['due_date'],
                    'amount' => $new['amount'],
                    'paid_amount' => '0.00',
                    'status' => PaymentService::statusFor($new['amount'], '0.00', CarbonImmutable::parse($new['due_date'])),
                ]);
            }

            $this->renumber($locked);
            $after = $this->snapshot($this->lockInstallments($locked));

            Audit::log('installments.restructured', sprintf(
                '%s numaralı kaydın ödeme planını yeniden yapılandırdı (%d güncellendi, %d eklendi, %d kaldırıldı). Kalan tutar %s TL korundu.%s',
                $locked->enrollment_no, count($plan['update']), count($plan['create']), count($plan['delete']) + count($plan['cancel']),
                Money::format(bcsub((string) $locked->net_price, $plan['paid_total'], 2)), $note ? " Not: {$note}" : '',
            ), $locked, ['before' => $before, 'after' => $after]);

            return $locked->load('installments');
        });
    }

    /**
     * Sonradan indirim / burs değişikliği. Net bedel farkı ödenmemiş taksitlere orantılı dağıtılır.
     *
     * @param array{discount_amount:string, discount_reason?:?string, scholarship_amount:string, scholarship_reason?:?string} $data
     */
    public function adjustPrice(Enrollment $enrollment, array $data): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $data) {
            /** @var Enrollment $locked */
            $locked = Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $installments = $this->lockInstallments($locked);

            $list = Money::of($locked->list_price);
            $discount = Money::of($data['discount_amount']);
            $scholarship = Money::of($data['scholarship_amount']);
            if (bccomp($discount, '0', 2) < 0 || bccomp($scholarship, '0', 2) < 0) {
                throw new BusinessRuleException('İndirim ve burs negatif olamaz.', 'negative_adjustment');
            }
            $newNet = bcsub(bcsub($list, $discount, 2), $scholarship, 2);
            if (bccomp($newNet, '0', 2) < 0) {
                throw new BusinessRuleException('İndirim ve burs toplamı liste fiyatını aşamaz.', 'negative_net_price');
            }

            $oldNet = Money::of($locked->net_price);
            $delta = bcsub($newNet, $oldNet, 2);
            $beforeInst = $this->snapshot($installments);
            $beforePrice = $locked->only(['discount_amount', 'discount_reason', 'scholarship_amount', 'scholarship_reason', 'net_price']);

            $editable = $installments->reject(fn (Installment $i) => in_array($i->status, ['paid', 'cancelled'], true))
                ->mapWithKeys(fn (Installment $i) => [$i->id => ['amount' => (string) $i->amount, 'paid_amount' => (string) $i->paid_amount]])->all();

            $result = InstallmentPlanRules::distributeDelta($editable, $delta);

            foreach ($result['amounts'] as $id => $amount) {
                /** @var Installment $inst */
                $inst = $installments->firstWhere('id', $id);
                if (bccomp($amount, (string) $inst->amount, 2) === 0) {
                    continue;
                }
                $paid = (string) $inst->paid_amount;
                $isPaid = bccomp($paid, $amount, 2) >= 0;
                $inst->forceFill([
                    'amount' => $amount,
                    'status' => PaymentService::statusFor($amount, $paid, $inst->due_date),
                    'paid_at' => $isPaid ? ($inst->paid_at ?? now()) : null,
                ])->save();
            }

            if (Money::isPositive($result['extra'])) {
                Installment::query()->create([
                    'branch_id' => $locked->branch_id,
                    'enrollment_id' => $locked->id,
                    'student_id' => $locked->student_id,
                    'sequence' => $this->maxSequence($locked) + 1,
                    'due_date' => CarbonImmutable::today()->addDays(30)->toDateString(),
                    'amount' => $result['extra'],
                    'paid_amount' => '0.00',
                    'status' => 'pending',
                ]);
            }

            $locked->forceFill([
                'discount_amount' => $discount,
                'discount_reason' => $data['discount_reason'] ?? null,
                'scholarship_amount' => $scholarship,
                'scholarship_reason' => $data['scholarship_reason'] ?? null,
                'net_price' => $newNet,
            ])->save();

            $this->renumber($locked);

            // Güvenlik ağı: plan toplamı net bedele eşit değilse hiçbir şey yazılmaz.
            $sum = (string) Installment::query()->where('enrollment_id', $locked->id)->where('status', '!=', 'cancelled')->sum('amount');
            if (bccomp(Money::of($sum), $newNet, 2) !== 0 && $installments->isNotEmpty()) {
                throw new BusinessRuleException(
                    sprintf('Plan toplamı (%s TL) yeni net bedelle (%s TL) uyuşmuyor; önce ödeme planını düzeltin.', Money::format($sum), Money::format($newNet)),
                    'plan_total_mismatch',
                );
            }

            Audit::log('enrollment.price_adjusted', sprintf(
                '%s numaralı kaydın net bedelini %s TL → %s TL olarak değiştirdi (indirim %s TL, burs %s TL); fark kalan taksitlere dağıtıldı.',
                $locked->enrollment_no, Money::format($oldNet), Money::format($newNet), Money::format($discount), Money::format($scholarship),
            ), $locked, [
                'before' => ['price' => $beforePrice, 'installments' => $beforeInst],
                'after' => ['price' => $locked->only(['discount_amount', 'discount_reason', 'scholarship_amount', 'scholarship_reason', 'net_price']), 'installments' => $this->snapshot($this->lockInstallments($locked))],
            ]);

            return $locked->load('installments');
        });
    }

    /** @return Collection<int, Installment> */
    private function lockInstallments(Enrollment $enrollment): Collection
    {
        return Installment::query()->where('enrollment_id', $enrollment->id)->orderBy('id')->lockForUpdate()->get();
    }

    private function rulesInput(Collection $installments): array
    {
        $withAllocations = DB::table('payment_allocations')->whereIn('installment_id', $installments->pluck('id'))->distinct()->pluck('installment_id')->flip();

        return $installments->map(fn (Installment $i) => [
            'id' => $i->id,
            'amount' => (string) $i->amount,
            'paid_amount' => (string) $i->paid_amount,
            'status' => $i->status,
            'due_date' => $i->due_date->toDateString(),
            'has_allocations' => isset($withAllocations[$i->id]),
        ])->values()->all();
    }

    private function snapshot(Collection $installments): array
    {
        return $installments->sortBy('sequence')->map(fn (Installment $i) => [
            'id' => $i->id, 'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(),
            'amount' => (string) $i->amount, 'paid_amount' => (string) $i->paid_amount, 'status' => $i->status,
        ])->values()->all();
    }

    private function maxSequence(Enrollment $enrollment): int
    {
        return (int) DB::table('installments')->where('enrollment_id', $enrollment->id)->max('sequence');
    }

    /**
     * Sıra numaralarını vade sırasına göre 1..n yeniden yazar (iptaller en sona).
     * Tekil anahtar (enrollment_id, sequence) çakışmasın diye iki geçiş: önce max+1.., sonra 1..n.
     * n farklı pozitif sıra için max >= n olduğundan iki aralık kesişmez.
     */
    private function renumber(Enrollment $enrollment): void
    {
        $rows = DB::table('installments')->where('enrollment_id', $enrollment->id)
            ->orderByRaw("status = 'cancelled'")->orderBy('due_date')->orderBy('id')->pluck('id')->values();
        $max = $this->maxSequence($enrollment);

        if ($max + $rows->count() > 255) {
            throw new BusinessRuleException('Bu kayıtta çok fazla taksit satırı var.', 'plan_too_many_rows');
        }

        foreach ($rows as $i => $id) {
            DB::table('installments')->where('id', $id)->update(['sequence' => $max + 1 + $i]);
        }
        foreach ($rows as $i => $id) {
            DB::table('installments')->where('id', $id)->update(['sequence' => $i + 1]);
        }
    }
}
