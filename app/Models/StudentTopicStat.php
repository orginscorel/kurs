<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTopicStat extends Model
{
    public $timestamps = false;

    protected $fillable = ['student_id', 'topic_id', 'asked', 'correct', 'wrong', 'last_exam_at'];

    protected $casts = ['last_exam_at' => 'datetime'];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
