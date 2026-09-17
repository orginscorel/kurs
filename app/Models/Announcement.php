<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Announcement extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'title', 'body', 'audience', 'channels', 'published_at', 'recipient_count', 'created_by'];

    protected $casts = ['audience' => 'array', 'channels' => 'array', 'published_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
