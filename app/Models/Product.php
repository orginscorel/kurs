<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'name', 'publisher', 'barcode', 'subject_id', 'purchase_price', 'sale_price', 'min_stock', 'is_active'];

    protected $casts = ['purchase_price' => 'decimal:2', 'sale_price' => 'decimal:2', 'is_active' => 'boolean'];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
