<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

class InstallmentMaintenance
{
    /** Vadesi geçmiş, tamamı ödenmemiş taksitleri GECİKTİ olarak işaretler. */
    public function markOverdue(?int $branchId = null): int
    {
        return DB::table('installments')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', now()->toDateString())
            ->whereColumn('paid_amount', '<', 'amount')
            ->update(['status' => 'overdue', 'updated_at' => now()]);
    }
}
