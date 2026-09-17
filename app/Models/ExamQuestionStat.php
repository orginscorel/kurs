<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamQuestionStat extends Model
{
    protected $fillable = ['exam_question_id', 'correct_count', 'wrong_count', 'blank_count', 'choice_distribution', 'most_common_wrong'];

    protected $casts = ['choice_distribution' => 'array'];
}
