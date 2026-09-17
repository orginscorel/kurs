<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Hesap planı satırı (TDHP kodu). */
class LedgerAccount extends Model
{
    use BelongsToBranch;

    public const TYPES = ['asset' => 'Varlık', 'liability' => 'Yabancı kaynak', 'equity' => 'Öz kaynak', 'income' => 'Gelir', 'expense' => 'Gider'];

    protected $fillable = ['branch_id', 'code', 'name', 'type', 'normal_side', 'is_active', 'is_system'];

    protected $casts = ['is_active' => 'boolean', 'is_system' => 'boolean'];
}
