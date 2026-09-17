<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGoal extends Model
{
    protected $fillable = ['student_id', 'university', 'department', 'target_rank', 'target_tyt_net', 'target_ayt_net', 'subject_targets', 'is_active'];

    protected $casts = ['subject_targets' => 'array', 'is_active' => 'boolean', 'target_tyt_net' => 'decimal:2', 'target_ayt_net' => 'decimal:2'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
