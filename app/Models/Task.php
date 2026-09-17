<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id', 'title', 'description', 'taskable_type', 'taskable_id', 'assigned_to', 'created_by',
        'due_at', 'priority', 'completed_at', 'reminded_at',
    ];

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime', 'reminded_at' => 'datetime'];

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
