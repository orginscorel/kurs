<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const EMPLOYMENT_TYPES = ['full_time' => 'Tam zamanlı', 'part_time' => 'Yarı zamanlı', 'hourly' => 'Saatlik'];

    protected $fillable = [
        'branch_id', 'user_id', 'first_name', 'last_name', 'title', 'specialty', 'phone', 'whatsapp_phone',
        'email', 'color', 'hired_on', 'employment_type', 'hourly_rate', 'max_weekly_hours', 'target_weekly_hours', 'in_timetable', 'is_active',
        'avatar_path', 'notes',
    ];

    protected $casts = ['hired_on' => 'date', 'is_active' => 'boolean', 'in_timetable' => 'boolean', 'hourly_rate' => 'decimal:2'];

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_subject');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LessonSchedule::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LessonSession::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(TeacherAvailability::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(TeacherLeave::class);
    }
}
