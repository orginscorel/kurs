<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const KINDS = ['classroom' => 'Derslik', 'study' => 'Etüt odası', 'hall' => 'Salon', 'lab' => 'Laboratuvar'];

    protected $fillable = ['branch_id', 'name', 'kind', 'capacity', 'floor', 'features', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer'];

    public function sessions(): HasMany
    {
        return $this->hasMany(LessonSession::class);
    }

    /** Denetim/belge ilişkileri için kararlı morph adı (AppServiceProvider haritasıyla uyumlu). */
    public function getMorphClass(): string
    {
        return 'classroom';
    }
}
