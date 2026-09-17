<?php

namespace App\Sync\Local;

use App\Models\FinanceEntry;
use App\Services\Finance\FinanceEntryService;

/** Yerel düğümde gelir/gider kaydı ve iptali komut olarak eşitlenir. */
class LocalFinanceEntryService extends FinanceEntryService
{
    public function record(array $data): FinanceEntry
    {
        return app(LocalCommandRecorder::class)->run('finance_entry.create', [
            'data' => array_intersect_key($data, array_flip([
                'direction', 'finance_category_id', 'finance_account_id', 'amount', 'entry_date', 'description', 'counterparty', 'document_no',
            ])),
        ], fn () => parent::record($data));
    }

    public function void(FinanceEntry $entry, string $reason): FinanceEntry
    {
        return app(LocalCommandRecorder::class)->run('finance_entry.void', ['entry' => $entry->id, 'reason' => $reason],
            fn () => parent::void($entry, $reason));
    }
}
