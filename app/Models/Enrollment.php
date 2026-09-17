<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'student_id', 'academic_term_id', 'program_id', 'education_package_id', 'class_group_id',
        'enrollment_no', 'list_price', 'discount_amount', 'discount_reason', 'scholarship_amount',
        'scholarship_reason', 'net_price', 'status', 'enrolled_on', 'ended_on', 'financial_guardian_id', 'created_by',
    ];

    protected $casts = [
        'list_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'scholarship_amount' => 'decimal:2',
        'net_price' => 'decimal:2',
        'enrolled_on' => 'date',
        'ended_on' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(EducationPackage::class, 'education_package_id');
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function financialGuardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'financial_guardian_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('sequence');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }
}
