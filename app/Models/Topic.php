<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    protected $fillable = ['subject_id', 'parent_id', 'name', 'outcome_code', 'sort'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Topic::class, 'parent_id');
    }

    /** Denetim/belge ilişkileri için kararlı morph adı (AppServiceProvider haritasıyla uyumlu). */
    public function getMorphClass(): string
    {
        return 'topic';
    }
}
