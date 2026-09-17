<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonSchedule extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'academic_term_id', 'class_group_id', 'subject_id', 'teacher_id', 'classroom_id',
        'weekday', 'starts_at', 'ends_at', 'valid_from', 'valid_until', 'is_locked',
    ];

    protected $casts = ['valid_from' => 'date', 'valid_until' => 'date', 'weekday' => 'integer', 'is_locked' => 'boolean'];

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LessonSession::class);
    }

    /** Denetim/belge ilişkileri için kararlı morph adı (AppServiceProvider haritasıyla uyumlu). */
    public function getMorphClass(): string
    {
        return 'lesson_schedule';
    }
}
