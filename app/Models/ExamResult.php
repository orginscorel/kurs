<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamResult extends Model
{
    protected $fillable = [
        'exam_id', 'student_id', 'class_group_id', 'booklet', 'correct', 'wrong', 'blank', 'net', 'score',
        'institution_rank', 'class_rank', 'national_rank', 'source',
    ];

    protected $casts = ['net' => 'decimal:2', 'score' => 'decimal:3'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamResultSection::class);
    }
}
