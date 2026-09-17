<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installment extends Model
{
    use BelongsToBranch;

    public const STATUSES = ['pending' => 'Bekliyor', 'partial' => 'Kısmi ödendi', 'paid' => 'Ödendi', 'overdue' => 'Gecikti', 'cancelled' => 'İptal'];

    protected $fillable = ['branch_id', 'enrollment_id', 'student_id', 'sequence', 'due_date', 'amount', 'paid_amount', 'status', 'paid_at'];

    protected $casts = ['due_date' => 'date', 'paid_at' => 'datetime', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** Kalan tutar (kuruş hassasiyetinde, string aritmetik). */
    public function remaining(): string
    {
        return bcsub((string) $this->amount, (string) $this->paid_amount, 2);
    }
}
