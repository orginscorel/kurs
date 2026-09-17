<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AccountTransaction extends Model
{
    use BelongsToBranch;

    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'finance_account_id', 'amount', 'balance_after', 'source_type', 'source_id', 'description', 'occurred_at', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Hesap hareketleri değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Hesap hareketleri silinemez.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
