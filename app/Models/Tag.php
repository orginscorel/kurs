<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'name', 'color'];

    public function students(): MorphToMany
    {
        return $this->morphedByMany(Student::class, 'taggable');
    }
}
