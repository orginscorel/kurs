<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class AcademicTerm extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'name', 'starts_on', 'ends_on', 'is_current'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'is_current' => 'boolean'];

    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->first();
    }
}
