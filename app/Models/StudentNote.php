<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentNote extends Model
{
    use SoftDeletes;

    protected $fillable = ['student_id', 'user_id', 'body', 'is_pinned'];

    protected $casts = ['is_pinned' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
