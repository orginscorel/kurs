<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'code', 'name', 'exam_track', 'kind', 'color', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class)->withPivot(['id', 'weekly_hours', 'curriculum']);
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(EducationPackage::class);
    }

    /** Denetim/belge ilişkileri için kararlı morph adı (AppServiceProvider haritasıyla uyumlu). */
    public function getMorphClass(): string
    {
        return 'program';
    }
}
