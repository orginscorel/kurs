<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use BelongsToBranch;

    public const STATUSES = ['present' => 'Var', 'absent' => 'Yok', 'late' => 'Geç', 'excused' => 'İzinli', 'medical' => 'Raporlu'];

    protected $fillable = [
        'branch_id', 'lesson_session_id', 'student_id', 'date', 'status', 'late_minutes', 'method', 'note',
        'recorded_by', 'guardian_notified_at',
    ];

    protected $casts = ['date' => 'date', 'guardian_notified_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LessonSession::class, 'lesson_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
