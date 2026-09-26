<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonSession extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id', 'lesson_schedule_id', 'class_group_id', 'subject_id', 'teacher_id', 'classroom_id',
        'date', 'starts_at', 'ends_at', 'status', 'cancel_reason', 'topic_note', 'topic_id',
        'attendance_taken_at', 'attendance_taken_by', 'makeup_of_id', 'holiday_id',
    ];

    protected $casts = [
        'date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'attendance_taken_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LessonSchedule::class, 'lesson_schedule_id');
    }

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

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** Tekil/legacy konu (geriye dönük uyumluluk; yeni akış topics() pivotunu kullanır). */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** Bu derste işlenen konular (çoklu). */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'lesson_session_topic')
            ->withPivot('sort')
            ->orderBy('lesson_session_topic.sort')
            ->orderBy('topics.name');
    }
}
