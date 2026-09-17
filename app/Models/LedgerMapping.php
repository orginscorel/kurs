<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class LedgerMapping extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'source_type', 'source_key', 'ledger_code'];
}
