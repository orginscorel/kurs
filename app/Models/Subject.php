<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'code', 'name', 'short_name', 'color', 'is_hard', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'is_hard' => 'boolean'];

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)->orderBy('sort');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'teacher_subject');
    }

    /** Denetim/belge ilişkileri için kararlı morph adı (AppServiceProvider haritasıyla uyumlu). */
    public function getMorphClass(): string
    {
        return 'subject';
    }
}
