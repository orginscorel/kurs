<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Tahsilat kaydı DEĞİŞTİRİLMEZ. Yalnızca iptal (void) alanları bir kez doldurulabilir.
 */
class Payment extends Model
{
    use BelongsToBranch;

    public const METHODS = [
        'cash' => 'Nakit', 'credit_card' => 'Kredi kartı', 'bank_transfer' => 'Havale', 'eft' => 'EFT',
        'pos' => 'POS', 'online' => 'Online ödeme', 'cheque' => 'Çek', 'other' => 'Diğer',
    ];

    private const MUTABLE_ONCE = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = [
        'branch_id', 'receipt_no', 'student_id', 'enrollment_id', 'guardian_id', 'finance_account_id', 'method',
        'amount', 'paid_at', 'reference', 'note', 'payer_name', 'received_by', 'idempotency_key',
    ];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (Payment $p) {
            $dirty = array_keys($p->getDirty());

            if (array_diff($dirty, self::MUTABLE_ONCE) !== [] || $p->getOriginal('voided_at') !== null) {
                throw new LogicException('Tahsilat kayıtları değiştirilemez; iptal edip yeniden kaydedin.');
            }
        });
        static::deleting(fn () => throw new LogicException('Tahsilat kayıtları silinemez.'));
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
