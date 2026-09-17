<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exam extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'exam_type_id', 'academic_term_id', 'name', 'publisher', 'scope', 'exam_date',
        'wrong_penalty_ratio', 'base_score', 'booklets', 'status', 'published_at', 'participant_count', 'created_by',
    ];

    protected $casts = [
        'exam_date' => 'date',
        'published_at' => 'datetime',
        'booklets' => 'array',
        'wrong_penalty_ratio' => 'decimal:2',
        'base_score' => 'decimal:3',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExamType::class, 'exam_type_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamSection::class)->orderBy('sort');
    }

    public function questions(): HasManyThrough
    {
        return $this->hasManyThrough(ExamQuestion::class, ExamSection::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(OpticalImport::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
