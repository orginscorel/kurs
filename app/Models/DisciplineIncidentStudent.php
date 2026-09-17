<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Olaya karışan öğrenci satırı: rol, davranış ve o andaki puan (katalog sonradan değişse de puan korunur). */
class DisciplineIncidentStudent extends Model
{
    protected $table = 'discipline_incident_students';

    protected $fillable = ['incident_id', 'student_id', 'behavior_id', 'role', 'penalty_points', 'merit_points', 'note'];

    protected $casts = ['penalty_points' => 'integer', 'merit_points' => 'integer'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplineIncident::class, 'incident_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function behavior(): BelongsTo
    {
        return $this->belongsTo(DisciplineBehavior::class, 'behavior_id');
    }
}
