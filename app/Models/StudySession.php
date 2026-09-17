<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StudySession extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id', 'kind', 'teacher_id', 'subject_id', 'classroom_id', 'topic', 'starts_at', 'ends_at',
        'capacity', 'status', 'requested_by', 'approved_by', 'fee', 'notes',
    ];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'fee' => 'decimal:2'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'study_session_student')->withPivot(['id', 'attendance']);
    }

    /** Denetim/belge ilişkileri için kararlı morph adı (AppServiceProvider haritasıyla uyumlu). */
    public function getMorphClass(): string
    {
        return 'study_session';
    }
}
