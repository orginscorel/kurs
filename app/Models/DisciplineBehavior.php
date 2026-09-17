<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Disiplin davranış kataloğu (olumsuz: ceza puanı, olumlu: ödül puanı). */
class DisciplineBehavior extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'code', 'name', 'category', 'kind', 'points', 'severity', 'suggested_sanction', 'description', 'is_active', 'sort_order'];

    protected $casts = ['points' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function isPositive(): bool
    {
        return $this->kind === 'positive';
    }
}
