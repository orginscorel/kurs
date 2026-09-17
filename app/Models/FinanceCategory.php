<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class FinanceCategory extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'direction', 'code', 'name', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];
}
