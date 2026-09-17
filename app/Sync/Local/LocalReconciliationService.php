<?php

namespace App\Sync\Local;

use App\Exceptions\BusinessRuleException;
use App\Models\PosSettlement;
use App\Services\Finance\ReconciliationService;
use Illuminate\Support\Facades\DB;

/**
 * Yerel düğümde POS yatışı ve iptali komut olarak eşitlenir (içteki aktarım + komisyon gideri aynı komutun parçası).
 * Banka ekstre eşleştirme işaretleri çevrimdışı ENGELLİ (ekstre çevrimiçi incelenir).
 */
class LocalReconciliationService extends ReconciliationService
{
    public function settle(array $data): PosSettlement
    {
        $ids = array_values(array_unique(array_map('intval', $data['detail_ids'] ?? [])));

        return app(LocalCommandRecorder::class)->run('pos_settlement.create', [
            'data' => ['detail_ids' => $ids] + array_intersect_key($data, array_flip([
                'pos_account_id', 'bank_account_id', 'deposit_date', 'actual_net', 'reference', 'note', 'idempotency_key',
            ])),
        ], function () use ($data, $ids) {
            app(LocalCommandRecorder::class)->touch('payment_card_details', $ids);   // toplu güncellenir

            return parent::settle($data);
        });
    }

    public function voidSettlement(PosSettlement $settlement, string $reason): PosSettlement
    {
        return app(LocalCommandRecorder::class)->run('pos_settlement.void', ['settlement' => $settlement->id, 'reason' => $reason], function () use ($settlement, $reason) {
            app(LocalCommandRecorder::class)->touch('payment_card_details', DB::table('payment_card_details')->where('pos_settlement_id', $settlement->id)->pluck('id'));

            return parent::voidSettlement($settlement, $reason);
        });
    }

    public function mark(array $transactionIds, string $statementDate, ?string $ref): int
    {
        throw self::offline();
    }

    public function unmark(array $transactionIds): int
    {
        throw self::offline();
    }

    private static function offline(): BusinessRuleException
    {
        return new BusinessRuleException('Banka ekstre eşleştirmesi çevrimdışı kurulumda yapılamıyor. İnternet bağlantısı varken web panelinden yapın.', 'offline_not_supported', [], 409);
    }
}
