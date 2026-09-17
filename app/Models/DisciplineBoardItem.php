<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplineBoardItem extends Model
{
    protected $fillable = ['meeting_id', 'incident_id', 'student_id', 'sanction_id', 'position', 'result', 'votes_for', 'votes_against', 'votes_abstain', 'decision'];

    protected $casts = ['votes_for' => 'integer', 'votes_against' => 'integer', 'votes_abstain' => 'integer', 'position' => 'integer'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(DisciplineBoardMeeting::class, 'meeting_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplineIncident::class, 'incident_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function sanction(): BelongsTo
    {
        return $this->belongsTo(DisciplineSanction::class, 'sanction_id');
    }
}
