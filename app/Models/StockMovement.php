<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToBranch;

    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'product_id', 'quantity', 'kind', 'student_id', 'unit_price', 'note', 'created_by'];

    protected $casts = ['unit_price' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
