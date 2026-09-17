<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResultSection extends Model
{
    public $timestamps = false;

    protected $fillable = ['exam_result_id', 'exam_section_id', 'answers', 'correct', 'wrong', 'blank', 'net'];

    protected $casts = ['net' => 'decimal:2'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }
}
