<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class ActivityFeed extends Model
{
    use BelongsToBranch;

    public $timestamps = false;

    protected $table = 'activity_feed';

    protected $fillable = ['branch_id', 'kind', 'message', 'subject_type', 'subject_id', 'student_id', 'meta', 'occurred_at'];

    protected $casts = ['meta' => 'array', 'occurred_at' => 'datetime'];
}
