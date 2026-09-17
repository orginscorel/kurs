<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $fillable = ['payment_id', 'installment_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
