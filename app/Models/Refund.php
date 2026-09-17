<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Tahsilat iadesi. Değiştirilmez; yalnız iptal alanları bir kez yazılır. */
class Refund extends Model
{
    use BelongsToBranch;

    private const MUTABLE_ONCE = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = [
        'branch_id', 'refund_no', 'student_id', 'payment_id', 'finance_account_id', 'method', 'amount', 'from_credit',
        'from_installments', 'invoiced_portion', 'refunded_at', 'reason', 'payee_name', 'reference', 'created_by', 'idempotency_key',
    ];

    protected $casts = ['amount' => 'decimal:2', 'from_credit' => 'decimal:2', 'from_installments' => 'decimal:2', 'invoiced_portion' => 'decimal:2', 'refunded_at' => 'datetime', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (Refund $r) {
            if (array_diff(array_keys($r->getDirty()), self::MUTABLE_ONCE) !== [] || $r->getOriginal('voided_at') !== null) {
                throw new LogicException('İade kayıtları değiştirilemez; iptal edin.');
            }
        });
        static::deleting(fn () => throw new LogicException('İade kayıtları silinemez.'));
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }
}
