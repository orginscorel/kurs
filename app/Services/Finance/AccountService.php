<?php

namespace App\Services\Finance;

use App\Services\Finance\FinanceAudit;
use App\Exceptions\BusinessRuleException;
use App\Models\AccountTransfer;
use App\Models\FinanceAccount;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Kasa / banka / POS hesapları, hesaplar arası transfer, açılış bakiyesi ve kasa sayım farkı.
 * Tüm bakiye değişimleri bir AccountTransfer belgesine bağlı Ledger::post hareketidir.
 *  - kind=transfer   : from → to, tutar pozitif
 *  - kind=opening    : to hesabına açılış bakiyesi (işaretli)
 *  - kind=adjustment : to hesabına sayım farkı (işaretli)
 */
class AccountService
{
    public const TRANSFER_KINDS = ['transfer' => 'Transfer', 'opening' => 'Açılış bakiyesi', 'adjustment' => 'Sayım farkı'];

    public function __construct(private readonly Ledger $ledger) {}

    /** @param array{kind:string, name:string, bank_name?:?string, iban?:?string, opening_balance?:?string, is_active?:bool} $data */
    public function create(array $data): FinanceAccount
    {
        if (! isset(FinanceAccount::KINDS[$data['kind']])) {
            throw new BusinessRuleException('Geçersiz hesap türü.', 'invalid_account_kind');
        }
        $opening = Money::of($data['opening_balance'] ?? '0');

        return DB::transaction(function () use ($data, $opening) {
            $account = FinanceAccount::query()->create([
                'kind' => $data['kind'],
                'name' => trim($data['name']),
                'bank_name' => $data['bank_name'] ?? null,
                'iban' => isset($data['iban']) ? strtoupper(preg_replace('/\s+/', '', $data['iban'])) : null,
                'currency' => 'TRY',
                'opening_balance' => $opening,
                'is_active' => true,
            ]);

            if (bccomp($opening, '0', 2) !== 0) {
                $this->postBalanceDocument('opening', $account, $opening, CarbonImmutable::today()->toDateString(), 'Açılış bakiyesi');
            }

            FinanceAudit::log('finance_account.created', sprintf('%s adlı %s hesabını oluşturdu (açılış %s TL).', $account->name, mb_strtolower(FinanceAccount::KINDS[$account->kind]), Money::format($opening)), $account);

            return $account->refresh();
        });
    }

    /** @param array{name:string, bank_name?:?string, iban?:?string, is_active?:bool} $data */
    public function update(FinanceAccount $account, array $data): FinanceAccount
    {
        $account->fill([
            'name' => trim($data['name']),
            'bank_name' => $data['bank_name'] ?? null,
            'iban' => ! empty($data['iban']) ? strtoupper(preg_replace('/\s+/', '', $data['iban'])) : null,
        ]);
        if (array_key_exists('is_active', $data)) {
            $account->is_active = (bool) $data['is_active'];
        }
        $account->save();

        $diff = Audit::diff($account);
        if ($diff['after']) {
            FinanceAudit::log('finance_account.updated', "{$account->name} hesabının bilgilerini güncelledi.", $account, $diff);
        }

        return $account;
    }

    public function delete(FinanceAccount $account): void
    {
        if (DB::table('account_transactions')->where('finance_account_id', $account->id)->exists()) {
            throw new BusinessRuleException('Hareketi olan hesap silinemez; pasife alabilirsiniz.', 'account_has_transactions');
        }
        $account->delete();
        FinanceAudit::log('finance_account.deleted', "{$account->name} hesabını sildi.", $account);
    }

    public function transfer(int $fromId, int $toId, string $amount, string $date, ?string $description): AccountTransfer
    {
        $amount = Money::of($amount);
        if (! Money::isPositive($amount)) {
            throw new BusinessRuleException('Transfer tutarı sıfırdan büyük olmalı.', 'invalid_amount');
        }
        if ($fromId === $toId) {
            throw new BusinessRuleException('Kaynak ve hedef hesap aynı olamaz.', 'same_account');
        }
        $day = CarbonImmutable::parse($date);
        if ($day->gt(CarbonImmutable::today())) {
            throw new BusinessRuleException('İleri tarihli transfer kaydedilemez.', 'future_date');
        }

        return DB::transaction(function () use ($fromId, $toId, $amount, $day, $description) {
            // Kilitler hep aynı sırada (id artan) alınır → karşılıklı transferlerde kilitlenme olmaz.
            $accounts = FinanceAccount::query()->whereIn('id', [$fromId, $toId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $accounts[$fromId] ?? throw new BusinessRuleException('Kaynak hesap bulunamadı.', 'account_not_found', [], 404);
            $to = $accounts[$toId] ?? throw new BusinessRuleException('Hedef hesap bulunamadı.', 'account_not_found', [], 404);

            $transfer = new AccountTransfer();
            $transfer->forceFill([
                'kind' => 'transfer',
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'transfer_date' => $day->toDateString(),
                'description' => $description ? mb_substr($description, 0, 300) : null,
                'created_by' => Auth::id(),
            ])->save();

            $occurred = $day->isToday() ? CarbonImmutable::now() : $day->setTime(12, 0);
            $label = "Transfer: {$from->name} → {$to->name}".($description ? " — {$description}" : '');
            $this->ledger->post($from->id, bcmul($amount, '-1', 2), $transfer, $label, $occurred);
            $this->ledger->post($to->id, $amount, $transfer, $label, $occurred);

            FinanceAudit::log('finance_account.transfer', sprintf('%s hesabından %s hesabına %s TL transfer yaptı.', $from->name, $to->name, Money::format($amount)), $transfer);

            return $transfer;
        });
    }

    /** Kasa sayım farkı: sayılan tutar ile sistem bakiyesi arasındaki fark tek hareketle kapatılır. */
    public function adjust(FinanceAccount $account, string $countedBalance, string $reason): AccountTransfer
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('Düzeltme gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }
        $counted = Money::of($countedBalance);

        return DB::transaction(function () use ($account, $counted, $reason) {
            $locked = FinanceAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $delta = bcsub($counted, (string) $locked->balance, 2);
            if (bccomp($delta, '0', 2) === 0) {
                throw new BusinessRuleException('Sayılan tutar sistem bakiyesiyle aynı; düzeltme gerekmiyor.', 'no_difference');
            }

            $doc = $this->postBalanceDocument('adjustment', $locked, $delta, CarbonImmutable::today()->toDateString(), 'Sayım farkı: '.trim($reason));

            FinanceAudit::log('finance_account.adjusted', sprintf(
                '%s hesabında sayım farkı kaydetti: sistem %s TL, sayılan %s TL, fark %s TL. Gerekçe: %s',
                $locked->name, Money::format($locked->getOriginal('balance')), Money::format($counted), Money::format($delta), $reason,
            ), $doc);

            return $doc;
        });
    }

    public function voidTransfer(AccountTransfer $transfer, string $reason): AccountTransfer
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('İptal gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }

        return DB::transaction(function () use ($transfer, $reason) {
            /** @var AccountTransfer $locked */
            $locked = AccountTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                throw new BusinessRuleException('Bu kayıt zaten iptal edilmiş.', 'already_voided');
            }

            $ids = array_values(array_filter([$locked->from_account_id, $locked->to_account_id]));
            FinanceAccount::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

            $label = 'İptal: '.(self::TRANSFER_KINDS[$locked->kind] ?? 'Transfer').($locked->description ? " — {$locked->description}" : '');
            $amount = (string) $locked->amount;
            if ($locked->kind === 'transfer') {
                // Önce hedeften düş (kasa eksiye düşecekse işlem durur), sonra kaynağa iade et.
                $this->ledger->post($locked->to_account_id, bcmul($amount, '-1', 2), $locked, $label, now());
                $this->ledger->post($locked->from_account_id, $amount, $locked, $label, now());
            } else {
                $this->ledger->post($locked->to_account_id, bcmul($amount, '-1', 2), $locked, $label, now());
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => mb_substr(trim($reason), 0, 300)])->save();

            FinanceAudit::log('finance_account.transfer_voided', sprintf('%s TL tutarındaki %s kaydını iptal etti. Gerekçe: %s', Money::format($amount), mb_strtolower(self::TRANSFER_KINDS[$locked->kind] ?? 'transfer'), $reason), $locked);

            return $locked;
        });
    }

    private function postBalanceDocument(string $kind, FinanceAccount $account, string $signedAmount, string $date, string $description): AccountTransfer
    {
        $doc = new AccountTransfer();
        $doc->forceFill([
            'kind' => $kind,
            'from_account_id' => null,
            'to_account_id' => $account->id,
            'amount' => $signedAmount,
            'transfer_date' => $date,
            'description' => mb_substr($description, 0, 300),
            'created_by' => Auth::id(),
        ])->save();

        $this->ledger->post($account->id, $signedAmount, $doc, $description, now());

        return $doc;
    }
}
