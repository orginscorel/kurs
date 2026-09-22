<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Öğrenciye koç ataması. Aktif atama is_active=true olan tek satırdır (tarihçe korunur). */
class CoachingAssignment extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'student_id', 'coach_id', 'assigned_at', 'is_active', 'note', 'assigned_by'];

    protected $casts = ['assigned_at' => 'datetime', 'is_active' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}
