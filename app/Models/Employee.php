<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'user_id', 'first_name', 'last_name', 'position', 'phone', 'email', 'hired_on', 'is_active'];

    protected $casts = ['hired_on' => 'date', 'is_active' => 'boolean'];

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
