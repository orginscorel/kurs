<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class ReconciliationMark extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'account_transaction_id', 'statement_date', 'statement_ref', 'matched_by'];

    protected $casts = ['statement_date' => 'date'];
}
