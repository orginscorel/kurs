<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'period', 'status', 'closed_at', 'closed_by', 'reopened_at', 'reopened_by', 'note'];

    protected $casts = ['closed_at' => 'datetime', 'reopened_at' => 'datetime'];
}
