<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentCardDetail extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'payment_id', 'commission_rate', 'commission_amount', 'net_amount', 'expected_deposit_date', 'card_installments', 'pos_settlement_id'];

    protected $casts = ['commission_rate' => 'decimal:3', 'commission_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'expected_deposit_date' => 'date'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
