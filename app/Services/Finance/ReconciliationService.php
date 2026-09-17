<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
use App\Models\FinanceAccount;
use App\Models\FinanceEntry;
use App\Models\PaymentCardDetail;
use App\Models\PosSettlement;
use App\Models\ReconciliationMark;
use App\Services\Accounting\Dec;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Banka / POS mutabakatı.
 *  - POS yatışı: seçilen kart tahsilatlarının brüt toplamı POS hesabından düşer; bankaya gerçekleşen net tutar
 *    transfer edilir, fark "POS komisyonu" gideri olarak yazılır (mevcut transfer ve gider servisleriyle; fişler otomatik).
 *  - Banka hareketi eşleştirme: hareket satırına dokunmadan "ekstrede görüldü" işareti.
 */
class ReconciliationService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly FinanceEntryService $entries,
    ) {}

    /**
     * @param array{pos_account_id:int, bank_account_id:int, deposit_date:string, actual_net:string, detail_ids:list<int>,
     *   reference?:?string, note?:?string, idempotency_key?:?string} $data
     */
    public function settle(array $data): PosSettlement
    {
        if (! empty($data['idempotency_key']) && ($existing = PosSettlement::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }
        $pos = FinanceAccount::query()->findOrFail($data['pos_account_id']);
        $bank = FinanceAccount::query()->findOrFail($data['bank_account_id']);
        if ($pos->kind !== 'pos') {
            throw new BusinessRuleException('Kaynak hesap POS türünde olmalı.', 'not_pos_account');
        }
        if ($bank->kind !== 'bank') {
            throw new BusinessRuleException('Yatışın geldiği hesap banka türünde olmalı.', 'not_bank_account');
        }
        $actualNet = Money::of($data['actual_net']);
        if (! Money::isPositive($actualNet)) {
            throw new BusinessRuleException('Bankaya yatan tutar sıfırdan büyük olmalı.', 'invalid_amount');
        }
        $ids = array_values(array_unique(array_map('intval', $data['detail_ids'] ?? [])));
        if (! $ids) {
            throw new BusinessRuleException('Yatışla eşleştirilecek en az bir kart tahsilatı seçin.', 'no_details');
        }

        return DB::transaction(function () use ($data, $pos, $bank, $actualNet, $ids) {
            $details = PaymentCardDetail::query()->whereIn('id', $ids)->with('payment')->lockForUpdate()->get();
            if ($details->count() !== count($ids)) {
                throw new BusinessRuleException('Seçilen tahsilatlardan bazıları bulunamadı.', 'details_missing');
            }
            $gross = '0.00';
            $expected = '0.00';
            foreach ($details as $d) {
                if ($d->pos_settlement_id) {
                    throw new BusinessRuleException("{$d->payment?->receipt_no} makbuzu başka bir yatışla eşleşmiş.", 'already_settled');
                }
                if (! $d->payment || $d->payment->voided_at) {
                    throw new BusinessRuleException("{$d->payment?->receipt_no} makbuzu iptal edilmiş; seçimden çıkarın.", 'payment_voided');
                }
                if ((int) $d->payment->finance_account_id !== $pos->id) {
                    throw new BusinessRuleException("{$d->payment->receipt_no} makbuzu {$pos->name} hesabına alınmamış.", 'account_mismatch');
                }
                $gross = bcadd($gross, (string) $d->payment->amount, 2);
                $expected = bcadd($expected, (string) $d->net_amount, 2);
            }
            if (bccomp($actualNet, $gross, 2) > 0) {
                throw new BusinessRuleException(sprintf('Yatan tutar seçilen tahsilatların brüt toplamını (%s TL) aşamaz.', Money::format($gross)), 'net_exceeds_gross');
            }
            $commission = bcsub($gross, $actualNet, 2);
            $date = CarbonImmutable::parse($data['deposit_date'])->toDateString();
            $label = sprintf('POS yatışı %s (%d işlem)', CarbonImmutable::parse($date)->format('d.m.Y'), $details->count());

            $transfer = $this->accounts->transfer($pos->id, $bank->id, $actualNet, $date, $label.($data['reference'] ?? null ? ' — '.$data['reference'] : ''));
            $entry = null;
            if (Money::isPositive($commission)) {
                $category = $this->entries->category('expense', 'pos_commission', 'POS komisyonu');
                $entry = $this->entries->record([
                    'direction' => 'expense', 'finance_category_id' => $category->id, 'finance_account_id' => $pos->id,
                    'amount' => $commission, 'entry_date' => $date, 'description' => "POS komisyonu — {$label}",
                    'counterparty' => $bank->bank_name ?: $bank->name, 'document_no' => $data['reference'] ?? null,
                ]);
            }

            $settlement = PosSettlement::query()->create([
                'pos_account_id' => $pos->id, 'bank_account_id' => $bank->id, 'deposit_date' => $date,
                'gross_amount' => $gross, 'commission_amount' => $commission, 'net_amount' => $actualNet, 'expected_net' => $expected,
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
                'account_transfer_id' => $transfer->id, 'finance_entry_id' => $entry?->id,
                'created_by' => Auth::id(), 'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
            PaymentCardDetail::query()->whereIn('id', $ids)->update(['pos_settlement_id' => $settlement->id, 'updated_at' => now()]);

            FinanceAudit::log('reconciliation.pos_settled', sprintf('%s: %d kart tahsilatı (brüt %s TL) %s hesabına %s TL net yatışla eşleştirildi; komisyon %s TL (beklenen net %s TL).',
                $label, $details->count(), Money::format($gross), $bank->name, Money::format($actualNet), Money::format($commission), Money::format($expected)), $settlement);

            return $settlement;
        });
    }

    public function voidSettlement(PosSettlement $settlement, string $reason): PosSettlement
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('İptal gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }

        return DB::transaction(function () use ($settlement, $reason) {
            $locked = PosSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at) {
                throw new BusinessRuleException('Bu yatış eşleştirmesi zaten iptal edilmiş.', 'already_voided');
            }
            if ($locked->finance_entry_id && ($entry = FinanceEntry::query()->find($locked->finance_entry_id)) && ! $entry->voided_at) {
                $this->entries->void($entry, 'POS yatışı iptali: '.$reason);
            }
            if ($locked->account_transfer_id && ($transfer = AccountTransfer::query()->find($locked->account_transfer_id)) && ! $transfer->voided_at) {
                $this->accounts->voidTransfer($transfer, 'POS yatışı iptali: '.$reason);
            }
            PaymentCardDetail::query()->where('pos_settlement_id', $locked->id)->update(['pos_settlement_id' => null, 'updated_at' => now()]);
            $locked->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => mb_substr(trim($reason), 0, 300)])->save();
            FinanceAudit::log('reconciliation.pos_voided', sprintf('%s TL net POS yatışı eşleştirmesini iptal etti. Gerekçe: %s', Money::format($locked->net_amount), $reason), $locked);

            return $locked;
        });
    }

    /**
     * Banka hareketlerini ekstreyle eşleşti olarak işaretler.
     *
     * @param list<int> $transactionIds
     */
    public function mark(array $transactionIds, string $statementDate, ?string $ref): int
    {
        $rows = AccountTransaction::query()->whereIn('id', $transactionIds)
            ->whereIn('finance_account_id', FinanceAccount::query()->whereIn('kind', ['bank', 'pos'])->select('id'))->pluck('id');
        $count = 0;
        foreach ($rows as $id) {
            $mark = ReconciliationMark::query()->firstOrCreate(['account_transaction_id' => $id], [
                'statement_date' => CarbonImmutable::parse($statementDate)->toDateString(),
                'statement_ref' => $ref ? mb_substr($ref, 0, 120) : null,
                'matched_by' => Auth::id(),
            ]);
            $count += $mark->wasRecentlyCreated ? 1 : 0;
        }
        if ($count) {
            FinanceAudit::log('reconciliation.bank_marked', sprintf('%d banka hareketini ekstreyle eşleşti olarak işaretledi (ekstre tarihi %s).', $count, CarbonImmutable::parse($statementDate)->format('d.m.Y')));
        }

        return $count;
    }

    /** @param list<int> $transactionIds */
    public function unmark(array $transactionIds): int
    {
        $count = ReconciliationMark::query()->whereIn('account_transaction_id', $transactionIds)->delete();
        if ($count) {
            FinanceAudit::log('reconciliation.bank_unmarked', "{$count} banka hareketinin eşleşme işaretini kaldırdı.");
        }

        return $count;
    }

    /** Bekleyen POS yatışları (valör gününe göre gruplu). */
    public function pendingSummary(int $branchId): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $rows = DB::table('payment_card_details as d')->join('payments as p', 'p.id', '=', 'd.payment_id')
            ->where('d.branch_id', $branchId)->whereNull('d.pos_settlement_id')->whereNull('p.voided_at')
            ->groupBy('d.expected_deposit_date', 'p.finance_account_id')
            ->selectRaw('d.expected_deposit_date AS date, p.finance_account_id AS account_id, COUNT(*) AS count, SUM(p.amount) AS gross, SUM(d.net_amount) AS net, SUM(d.commission_amount) AS commission')
            ->orderBy('d.expected_deposit_date')->get();

        $total = ['count' => 0, 'gross' => '0.00', 'net' => '0.00', 'overdue_count' => 0, 'overdue_net' => '0.00'];
        $groups = [];
        foreach ($rows as $r) {
            $overdue = $r->date < $today;
            $total['count'] += (int) $r->count;
            $total['gross'] = bcadd($total['gross'], Dec::norm($r->gross), 2);
            $total['net'] = bcadd($total['net'], Dec::norm($r->net), 2);
            if ($overdue) {
                $total['overdue_count'] += (int) $r->count;
                $total['overdue_net'] = bcadd($total['overdue_net'], Dec::norm($r->net), 2);
            }
            $groups[] = ['date' => $r->date, 'account_id' => (int) $r->account_id, 'count' => (int) $r->count, 'gross' => Dec::round(Dec::norm($r->gross)),
                'net' => Dec::round(Dec::norm($r->net)), 'commission' => Dec::round(Dec::norm($r->commission)), 'overdue' => $overdue];
        }

        return ['groups' => $groups, 'total' => $total];
    }
}
