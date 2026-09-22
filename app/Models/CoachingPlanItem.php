<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Plan kalemi: ders + hedef (soru/saat/konu) + yapıldı mı. Şube kapsamı plan üzerinden çözülür. */
class CoachingPlanItem extends Model
{
    public const TARGET_KINDS = ['questions' => 'Soru', 'hours' => 'Saat', 'topic' => 'Konu'];

    protected $fillable = ['coaching_plan_id', 'subject', 'target_kind', 'target', 'is_done', 'done_at', 'position'];

    protected $casts = ['is_done' => 'boolean', 'done_at' => 'datetime'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CoachingPlan::class, 'coaching_plan_id');
    }
}
