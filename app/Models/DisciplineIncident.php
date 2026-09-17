<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Disiplin olay tutanağı. Öğrenciler discipline_incident_students üzerinden (rol + davranış + puan). */
class DisciplineIncident extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'incident_no', 'academic_term_id', 'kind', 'occurred_at', 'location', 'class_group_id', 'subject_id', 'teacher_id',
        'title', 'description', 'witnesses', 'severity', 'status', 'outcome', 'source', 'reported_by', 'guardian_notified_at', 'guardian_notified_via',
        'decided_at', 'closed_at',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'guardian_notified_at' => 'datetime', 'decided_at' => 'datetime', 'closed_at' => 'datetime'];

    public function participants(): HasMany
    {
        return $this->hasMany(DisciplineIncidentStudent::class, 'incident_id');
    }

    public function sanctions(): HasMany
    {
        return $this->hasMany(DisciplineSanction::class, 'incident_id');
    }

    public function defenses(): HasMany
    {
        return $this->hasMany(DisciplineDefense::class, 'incident_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DisciplineEvent::class, 'incident_id');
    }

    public function boardItems(): HasMany
    {
        return $this->hasMany(DisciplineBoardItem::class, 'incident_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

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

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
