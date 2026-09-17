<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPresence extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'student_id', 'date', 'first_entry_at', 'last_exit_at', 'is_inside', 'minutes_inside', 'last_event_at'];

    protected $casts = [
        'date' => 'date',
        'first_entry_at' => 'datetime',
        'last_exit_at' => 'datetime',
        'last_event_at' => 'datetime',
        'is_inside' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
