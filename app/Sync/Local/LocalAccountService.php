<?php

namespace App\Sync\Local;

use App\Models\AccountTransfer;
use App\Services\Finance\AccountService;

/**
 * Yerel düğümde hesaplar arası aktarım ve iptali komut olarak eşitlenir.
 * Hesap açma/düzenleme, açılış bakiyesi ve sayım farkı (anlık bakiyeye bağlı) çevrimdışı ENGELLİ kalır.
 */
class LocalAccountService extends AccountService
{
    public function transfer(int $fromId, int $toId, string $amount, string $date, ?string $description): AccountTransfer
    {
        return app(LocalCommandRecorder::class)->run('account_transfer.create', [
            'from' => $fromId, 'to' => $toId, 'amount' => $amount, 'date' => $date, 'description' => $description,
        ], fn () => parent::transfer($fromId, $toId, $amount, $date, $description));
    }

    public function voidTransfer(AccountTransfer $transfer, string $reason): AccountTransfer
    {
        return app(LocalCommandRecorder::class)->run('account_transfer.void', ['transfer' => $transfer->id, 'reason' => $reason],
            fn () => parent::voidTransfer($transfer, $reason));
    }
}
