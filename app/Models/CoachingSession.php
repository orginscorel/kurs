<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Akademik koçluk görüşmesi (rehberlik görüşmesinden AYRI). */
class CoachingSession extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'student_id', 'coach_id', 'held_at', 'topics', 'focus',
        'motivation', 'action_items', 'next_session_on',
    ];

    protected $casts = ['held_at' => 'datetime', 'next_session_on' => 'date'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}
