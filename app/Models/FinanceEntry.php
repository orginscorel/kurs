<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FinanceEntry extends Model
{
    use BelongsToBranch;

    private const MUTABLE_ONCE = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = [
        'branch_id', 'direction', 'finance_category_id', 'finance_account_id', 'amount', 'entry_date',
        'description', 'counterparty', 'document_no', 'created_by',
    ];

    protected $casts = ['amount' => 'decimal:2', 'entry_date' => 'date', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (FinanceEntry $e) {
            if (array_diff(array_keys($e->getDirty()), self::MUTABLE_ONCE) !== [] || $e->getOriginal('voided_at') !== null) {
                throw new LogicException('Muhasebe kayıtları değiştirilemez; iptal edip yeniden kaydedin.');
            }
        });
        static::deleting(fn () => throw new LogicException('Muhasebe kayıtları silinemez.'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
