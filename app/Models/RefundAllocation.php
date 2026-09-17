<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundAllocation extends Model
{
    protected $fillable = ['refund_id', 'installment_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
