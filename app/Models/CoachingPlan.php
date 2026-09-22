<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Haftalık çalışma planı: bir öğrenci + hafta başı (pazartesi). Kalemler coaching_plan_items. */
class CoachingPlan extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'student_id', 'coach_id', 'week_start', 'note'];

    protected $casts = ['week_start' => 'date'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CoachingPlanItem::class)->orderBy('position')->orderBy('id');
    }
}
