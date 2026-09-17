<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'name', 'kind', 'city', 'district', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
