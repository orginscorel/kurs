<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Yazılı savunma istemi ve savunma metni (personel girer ya da öğrenci portaldan yazar). */
class DisciplineDefense extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'incident_id', 'student_id', 'requested_by', 'requested_at', 'due_on', 'status', 'request_note',
        'statement', 'submitted_at', 'submitted_via', 'recorded_by'];

    protected $casts = ['requested_at' => 'datetime', 'due_on' => 'date', 'submitted_at' => 'datetime'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplineIncident::class, 'incident_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'requested' && $this->due_on !== null && $this->due_on->lt(today());
    }
}
