<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Homework extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $table = 'homework';

    protected $fillable = [
        'branch_id', 'teacher_id', 'subject_id', 'class_group_id', 'topic_id', 'title', 'description',
        'assigned_at', 'due_at',
    ];

    protected $casts = ['assigned_at' => 'datetime', 'due_at' => 'datetime'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /** Belge/akış ilişkileri için kararlı morph adı (AppServiceProvider haritasına eklenene kadar da çalışır). */
    public function getMorphClass(): string
    {
        return 'homework';
    }
}
