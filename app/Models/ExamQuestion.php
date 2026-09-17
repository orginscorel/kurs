<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamQuestion extends Model
{
    public $timestamps = false;

    protected $fillable = ['exam_section_id', 'number', 'booklet_map', 'topic_id', 'is_cancelled'];

    protected $casts = ['booklet_map' => 'array', 'is_cancelled' => 'boolean'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(ExamQuestionStat::class);
    }
}
