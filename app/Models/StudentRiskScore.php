<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentRiskScore extends Model
{
    public $timestamps = false;

    /** Risk seviyesi geçişi (→ high) otomasyonu bu model olayından beslenir (bkz. App\Listeners\Automation\OnRiskScoreSaved). */
    protected $dispatchesEvents = ['saved' => \App\Events\StudentRiskScoreSaved::class];

    protected $fillable = ['student_id', 'score', 'level', 'factors', 'insights', 'calculated_at'];

    protected $casts = ['factors' => 'array', 'insights' => 'array', 'calculated_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
