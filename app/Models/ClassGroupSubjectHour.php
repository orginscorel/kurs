<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sınıfa özel müfredat satırı: haftalık saat, sabit öğretmen, günlük üst sınır, blok ders. */
class ClassGroupSubjectHour extends Model
{
    protected $fillable = ['class_group_id', 'subject_id', 'weekly_hours', 'teacher_id', 'max_per_day', 'block_size'];

    protected $casts = ['weekly_hours' => 'integer', 'max_per_day' => 'integer', 'block_size' => 'integer'];

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
