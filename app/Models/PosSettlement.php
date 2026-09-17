<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** POS → banka yatışı (mutabakat). Para hareketi bağlı transfer + komisyon gideriyle yapılır. */
class PosSettlement extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id', 'pos_account_id', 'bank_account_id', 'deposit_date', 'gross_amount', 'commission_amount', 'net_amount',
        'expected_net', 'reference', 'note', 'account_transfer_id', 'finance_entry_id', 'created_by', 'idempotency_key',
    ];

    protected $casts = ['deposit_date' => 'date', 'gross_amount' => 'decimal:2', 'commission_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'expected_net' => 'decimal:2', 'voided_at' => 'datetime'];

    public function cardDetails(): HasMany
    {
        return $this->hasMany(PaymentCardDetail::class);
    }

    public function posAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'pos_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'bank_account_id');
    }
}
