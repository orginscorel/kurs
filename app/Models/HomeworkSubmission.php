<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeworkSubmission extends Model
{
    public const STATUSES = ['assigned' => 'Atandı', 'seen' => 'Görüldü', 'submitted' => 'Teslim edildi', 'late' => 'Geç teslim', 'missed' => 'Yapılmadı'];

    protected $fillable = ['homework_id', 'student_id', 'status', 'seen_at', 'submitted_at', 'score', 'teacher_note'];

    protected $casts = ['seen_at' => 'datetime', 'submitted_at' => 'datetime', 'graded_at' => 'datetime'];

    public function homework(): BelongsTo
    {
        return $this->belongsTo(Homework::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
