<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherLeave extends Model
{
    protected $fillable = ['teacher_id', 'starts_on', 'ends_on', 'kind', 'reason', 'status'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
