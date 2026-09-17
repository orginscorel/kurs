<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplineAppeal extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'sanction_id', 'student_id', 'appellant', 'appealed_on', 'reason', 'status', 'result_note',
        'decided_by', 'decided_at', 'recorded_by'];

    protected $casts = ['appealed_on' => 'date', 'decided_at' => 'datetime'];

    public function sanction(): BelongsTo
    {
        return $this->belongsTo(DisciplineSanction::class, 'sanction_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
