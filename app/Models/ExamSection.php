<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSection extends Model
{
    public $timestamps = false;

    protected $fillable = ['exam_id', 'subject_id', 'code', 'name', 'question_count', 'coefficient', 'sort'];

    protected $casts = ['coefficient' => 'decimal:4', 'question_count' => 'integer'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('number');
    }
}
