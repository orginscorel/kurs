<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Ödeme planı yeniden yapılandırma kuralları (saf mantık — veritabanı yok, birim testli).
 *
 * Değişmezler:
 *  - Tamamı ödenmiş ve iptal edilmiş taksitler dokunulmazdır.
 *  - Kısmen ödenmiş taksit plandan çıkarılamaz; tutarı ödenen kısmın altına inemez.
 *  - Plan toplamı (ödenmiş taksitler + düzenlenen taksitler) kaydın net bedeline KURUŞU KURUŞUNA eşit olmalıdır.
 *    Eşdeğer ifade: düzenlenen taksitlerin kalanı = net bedel − ödenen.
 */
final class InstallmentPlanRules
{
    public const MAX_ROWS = 60;

    /**
     * @param list<array{id:int, amount:string, paid_amount:string, status:string, due_date:string, has_allocations:bool}> $existing  kaydın TÜM taksitleri
     * @param list<array{id?:int|null, due_date:string, amount:string|int|float}> $proposed  düzenlenebilir taksitlerin istenen son hâli
     * @return array{
     *   update: array<int, array{due_date:string, amount:string}>,
     *   create: list<array{due_date:string, amount:string}>,
     *   delete: list<int>,
     *   cancel: list<int>,
     *   locked_total: string, editable_total: string, paid_total: string
     * }
     */
    public static function restructure(string $netPrice, array $existing, array $proposed): array
    {
        $net = Money::of($netPrice);
        $byId = [];
        $lockedTotal = '0.00';
        $paidTotal = '0.00';

        foreach ($existing as $row) {
            $byId[(int) $row['id']] = $row;
            $paidTotal = bcadd($paidTotal, Money::of($row['paid_amount']), 2);
            if (self::isLocked($row)) {
                if ($row['status'] !== 'cancelled') {
                    $lockedTotal = bcadd($lockedTotal, Money::of($row['amount']), 2);
                }
            }
        }

        if (count($proposed) === 0 && bccomp($lockedTotal, $net, 2) !== 0) {
            throw new BusinessRuleException('Ödenmemiş bakiye için en az bir taksit gerekir.', 'plan_rows_required');
        }

        $update = [];
        $create = [];
        $seen = [];
        $editableTotal = '0.00';

        foreach (array_values($proposed) as $i => $row) {
            $line = $i + 1;
            $amount = Money::of($row['amount'] ?? null);
            $due = self::date($row['due_date'] ?? null, $line);

            if (! Money::isPositive($amount)) {
                throw new BusinessRuleException("{$line}. satır: taksit tutarı sıfırdan büyük olmalı.", 'plan_invalid_amount', ['row' => $line]);
            }

            $id = isset($row['id']) && $row['id'] !== null && $row['id'] !== '' ? (int) $row['id'] : null;

            if ($id !== null) {
                if (! isset($byId[$id])) {
                    throw new BusinessRuleException("{$line}. satır: taksit bu kayda ait değil.", 'plan_unknown_installment', ['row' => $line]);
                }
                if (isset($seen[$id])) {
                    throw new BusinessRuleException("{$line}. satır: aynı taksit iki kez gönderildi.", 'plan_duplicate_installment', ['row' => $line]);
                }
                if (self::isLocked($byId[$id])) {
                    throw new BusinessRuleException("{$line}. satır: ödenmiş ya da iptal edilmiş taksit değiştirilemez.", 'plan_locked_installment', ['row' => $line]);
                }
                $paid = Money::of($byId[$id]['paid_amount']);
                if (bccomp($amount, $paid, 2) < 0) {
                    throw new BusinessRuleException(
                        sprintf('%d. satır: tutar bu taksitte ödenmiş %s TL\'nin altına inemez.', $line, Money::format($paid)),
                        'plan_below_paid', ['row' => $line, 'paid' => $paid],
                    );
                }
                $seen[$id] = true;
                $update[$id] = ['due_date' => $due, 'amount' => $amount];
            } else {
                $create[] = ['due_date' => $due, 'amount' => $amount];
            }

            $editableTotal = bcadd($editableTotal, $amount, 2);
        }

        $delete = [];
        $cancel = [];
        foreach ($byId as $id => $row) {
            if (self::isLocked($row) || isset($seen[$id])) {
                continue;
            }
            if (bccomp(Money::of($row['paid_amount']), '0', 2) > 0) {
                throw new BusinessRuleException('Kısmen ödenmiş taksit plandan çıkarılamaz; tutarını ya da vadesini değiştirebilirsiniz.', 'plan_partial_removed', ['installment_id' => $id]);
            }
            // İptal edilmiş tahsilat geçmişi olan taksit silinmez, "iptal" olarak kalır.
            if ($row['has_allocations']) {
                $cancel[] = $id;
            } else {
                $delete[] = $id;
            }
        }

        $activeCount = count(array_filter($existing, fn ($r) => self::isLocked($r) && $r['status'] !== 'cancelled')) + count($proposed);
        if ($activeCount > self::MAX_ROWS) {
            throw new BusinessRuleException('Bir kayıtta en fazla '.self::MAX_ROWS.' taksit olabilir.', 'plan_too_many_rows');
        }

        $total = bcadd($lockedTotal, $editableTotal, 2);
        if (bccomp($total, $net, 2) !== 0) {
            $diff = bcsub($net, $total, 2);
            throw new BusinessRuleException(
                sprintf(
                    'Plan toplamı net bedele eşit olmalı. Net bedel %s TL, plan toplamı %s TL (%s %s TL).',
                    Money::format($net), Money::format($total), bccomp($diff, '0', 2) > 0 ? 'eksik' : 'fazla', Money::format(ltrim($diff, '-')),
                ),
                'plan_total_mismatch',
                ['net_price' => $net, 'plan_total' => $total, 'difference' => $diff, 'required_editable_total' => bcsub($net, $lockedTotal, 2)],
            );
        }

        return [
            'update' => $update,
            'create' => $create,
            'delete' => $delete,
            'cancel' => $cancel,
            'locked_total' => $lockedTotal,
            'editable_total' => $editableTotal,
            'paid_total' => $paidTotal,
        ];
    }

    /**
     * Fiyat değişikliğini (indirim/burs) ödenmemiş taksitlere, kalan tutarlarıyla orantılı dağıtır.
     * Negatif delta = indirim (kalan azalır), pozitif delta = artış. Kuruş artığı sondan başa dağıtılır.
     *
     * @param array<int, array{amount:string, paid_amount:string}> $rows  vade sırasına göre, yalnız düzenlenebilir taksitler
     * @return array{amounts: array<int, string>, extra: string}  extra: taksit yoksa yeni taksite yazılacak artış
     */
    public static function distributeDelta(array $rows, string $delta): array
    {
        $delta = Money::of($delta);
        $amounts = array_map(fn ($r) => Money::of($r['amount']), $rows);

        if (bccomp($delta, '0', 2) === 0) {
            return ['amounts' => $amounts, 'extra' => '0.00'];
        }

        $remaining = [];
        $totalRemainingCents = '0';
        foreach ($rows as $id => $r) {
            $cents = bcmul(bcsub(Money::of($r['amount']), Money::of($r['paid_amount']), 2), '100', 0);
            $cents = bccomp($cents, '0', 0) < 0 ? '0' : $cents;
            $remaining[$id] = $cents;
            $totalRemainingCents = bcadd($totalRemainingCents, $cents, 0);
        }

        $reduce = bccomp($delta, '0', 2) < 0;
        $absCents = bcmul(ltrim($delta, '-'), '100', 0);

        if ($reduce && bccomp($absCents, $totalRemainingCents, 0) > 0) {
            throw new BusinessRuleException(
                sprintf('İndirim ödenmemiş bakiyeden (%s TL) büyük olamaz; fazla ödeme için iade gerekir.', Money::format(bcdiv($totalRemainingCents, '100', 2))),
                'adjustment_exceeds_remaining',
            );
        }

        if (bccomp($totalRemainingCents, '0', 0) === 0) {
            // Açık taksit yok: artış yeni taksit olarak eklenir.
            return ['amounts' => $amounts, 'extra' => $reduce ? '0.00' : bcdiv($absCents, '100', 2)];
        }

        $shares = [];
        $assigned = '0';
        foreach ($remaining as $id => $cents) {
            $share = bcdiv(bcmul($absCents, $cents, 0), $totalRemainingCents, 0);
            $shares[$id] = $share;
            $assigned = bcadd($assigned, $share, 0);
        }

        $left = bcsub($absCents, $assigned, 0);
        $ids = array_reverse(array_keys($remaining));
        while (bccomp($left, '0', 0) > 0) {
            $progress = false;
            foreach ($ids as $id) {
                if (bccomp($left, '0', 0) <= 0) {
                    break;
                }
                if ($remaining[$id] === '0') {
                    continue;
                }
                if ($reduce && bccomp($shares[$id], $remaining[$id], 0) >= 0) {
                    continue;
                }
                $shares[$id] = bcadd($shares[$id], '1', 0);
                $left = bcsub($left, '1', 0);
                $progress = true;
            }
            if (! $progress) {
                throw new BusinessRuleException('Tutar taksitlere dağıtılamadı.', 'adjustment_distribution_failed');
            }
        }

        foreach ($shares as $id => $share) {
            $money = bcdiv($share, '100', 2);
            $amounts[$id] = $reduce ? bcsub($amounts[$id], $money, 2) : bcadd($amounts[$id], $money, 2);
        }

        return ['amounts' => $amounts, 'extra' => '0.00'];
    }

    /** @param array{status:string} $row */
    public static function isLocked(array $row): bool
    {
        return in_array($row['status'], ['paid', 'cancelled'], true);
    }

    private static function date(mixed $value, int $line): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new BusinessRuleException("{$line}. satır: vade tarihi geçersiz.", 'plan_invalid_date', ['row' => $line]);
        }
        try {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $d = false;
        }
        if (! $d || $d->format('Y-m-d') !== $value) {
            throw new BusinessRuleException("{$line}. satır: vade tarihi geçersiz.", 'plan_invalid_date', ['row' => $line]);
        }

        return $value;
    }
}
