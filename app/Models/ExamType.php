<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class ExamType extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'code', 'name', 'wrong_penalty_ratio', 'base_score', 'sections', 'is_active'];

    protected $casts = ['sections' => 'array', 'is_active' => 'boolean', 'wrong_penalty_ratio' => 'decimal:2', 'base_score' => 'decimal:3'];
}
