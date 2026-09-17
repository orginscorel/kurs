<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Verilen yaptırım. Uzaklaştırmada starts_on–ends_on + days; süreli yaptırım expires_on'da düşer. */
class DisciplineSanction extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'sanction_no', 'incident_id', 'student_id', 'sanction_type_id', 'board_meeting_id', 'status',
        'decision_note', 'duty_description', 'starts_on', 'ends_on', 'days', 'expires_on', 'visible_to_portal', 'decided_by', 'decided_at',
        'guardian_notified_at', 'cancel_reason'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'expires_on' => 'date', 'days' => 'integer', 'visible_to_portal' => 'boolean',
        'decided_at' => 'datetime', 'guardian_notified_at' => 'datetime'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplineIncident::class, 'incident_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DisciplineSanctionType::class, 'sanction_type_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(DisciplineBoardMeeting::class, 'board_meeting_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(DisciplineAppeal::class, 'sanction_id');
    }
}
