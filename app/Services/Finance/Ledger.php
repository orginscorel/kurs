<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\AccountTransaction;
use App\Models\FinanceAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Hesap defteri. Bakiye değişiminin TEK yolu. Çağıran taraf transaction içinde olmalıdır;
 * hesap satırı kilitlenir, hareket yazılır, önbellek bakiye aynı anda güncellenir.
 */
class Ledger
{
    public function post(int $accountId, string $signedAmount, Model $source, string $description, \DateTimeInterface $occurredAt): AccountTransaction
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Ledger::post bir transaction içinde çağrılmalıdır.');
        }

        /** @var FinanceAccount $account */
        $account = FinanceAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();

        if (! $account->is_active) {
            throw new BusinessRuleException("\"{$account->name}\" hesabı pasif durumda.", 'account_inactive');
        }

        $newBalance = bcadd((string) $account->balance, $signedAmount, 2);

        // Nakit kasa eksiye düşemez (banka/POS hesabında ekstre farkları olabilir).
        // İstisna: çevrimdışı cihazın işlemi sunucuda yeniden yürütülüyorsa para fiilen hareket etmiştir →
        // reddedilmez, eşitleme mutabakat kuyruğuna not düşülür (docs/SYNC.md › Çakışma kuralları).
        if ($account->kind === 'cash' && bccomp($newBalance, '0', 2) < 0 && app(\App\Sync\SyncContext::class)->replayingDeviceCommand()) {
            app(\App\Sync\SyncContext::class)->noteNegativeCash($account->name, $newBalance);
        } elseif ($account->kind === 'cash' && bccomp($newBalance, '0', 2) < 0) {
            throw new BusinessRuleException(
                "\"{$account->name}\" kasasında yeterli bakiye yok.",
                'insufficient_cash',
                ['balance' => (string) $account->balance],
            );
        }

        $account->forceFill(['balance' => $newBalance])->save();

        return AccountTransaction::query()->create([
            'branch_id' => $account->branch_id,
            'finance_account_id' => $account->id,
            'amount' => $signedAmount,
            'balance_after' => $newBalance,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'description' => mb_substr($description, 0, 300),
            'occurred_at' => $occurredAt,
            'created_by' => Auth::id(),
        ]);
    }
}
