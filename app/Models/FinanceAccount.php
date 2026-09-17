<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceAccount extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const KINDS = ['cash' => 'Kasa', 'bank' => 'Banka', 'pos' => 'POS'];

    protected $fillable = ['branch_id', 'kind', 'name', 'bank_name', 'iban', 'currency', 'opening_balance', 'is_active'];

    protected $casts = ['opening_balance' => 'decimal:2', 'balance' => 'decimal:2', 'is_active' => 'boolean'];

    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }
}
