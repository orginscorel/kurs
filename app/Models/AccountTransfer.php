<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountTransfer extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'from_account_id', 'to_account_id', 'amount', 'transfer_date', 'description', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'transfer_date' => 'date', 'voided_at' => 'datetime'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'from_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'to_account_id');
    }
}
