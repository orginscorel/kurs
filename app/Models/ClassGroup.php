<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassGroup extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'academic_term_id', 'program_id', 'homeroom_classroom_id', 'advisor_teacher_id',
        'name', 'capacity', 'is_active', 'grade_level', 'section', 'track', 'short_name', 'color',
    ];

    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer', 'grade_level' => 'integer'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function homeroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'homeroom_classroom_id');
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'advisor_teacher_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)->withPivot(['id', 'joined_on', 'left_on'])->withTimestamps();
    }

    public function activeStudents(): BelongsToMany
    {
        return $this->students()->wherePivotNull('left_on');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LessonSchedule::class);
    }

    /** Sınıfa özel ders saatleri (program_subject'i geçersiz kılar). */
    public function subjectHours(): HasMany
    {
        return $this->hasMany(ClassGroupSubjectHour::class);
    }

    public function timeTemplates(): BelongsToMany
    {
        return $this->belongsToMany(TimeTemplate::class, 'class_group_time_template');
    }
}
