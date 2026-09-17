<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRun extends Model
{
    protected $fillable = ['automation_rule_id', 'subject_type', 'subject_id', 'status', 'run_at', 'result', 'dedupe_key'];

    protected $casts = ['run_at' => 'datetime'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
