<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EducationPackage extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'program_id', 'academic_term_id', 'name', 'list_price', 'default_installments', 'includes', 'is_active'];

    protected $casts = ['list_price' => 'decimal:2', 'is_active' => 'boolean'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
