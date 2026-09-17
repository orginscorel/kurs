<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Senet (bono) — taksit başına bir geçerli kayıt. */
class PromissoryNote extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id', 'note_no', 'installment_id', 'enrollment_id', 'student_id', 'guardian_id', 'amount', 'due_date', 'issue_date',
        'issue_place', 'payment_place', 'payee_name', 'debtor_name', 'debtor_tax_id', 'debtor_address', 'created_by',
    ];

    protected $hidden = ['debtor_tax_id'];

    protected $casts = [
        'amount' => 'decimal:2', 'due_date' => 'date', 'issue_date' => 'date', 'debtor_tax_id' => \App\Casts\DataEncrypted::class,
        'first_printed_at' => 'datetime', 'last_printed_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
