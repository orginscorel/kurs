<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 3D derslik tasarımı + oturma düzeni. `data` (JSON) biçimi ön yüzdeki
 * resources/js/modules/classroom-design/types.ts › LayoutData ile aynıdır.
 */
class ClassroomLayout extends Model
{
    use BelongsToBranch, SoftDeletes;

    /** Sürüm geçmişinde tutulan en fazla kayıt (eskiler budanır) */
    public const MAX_VERSIONS = 50;

    protected $fillable = ['branch_id', 'classroom_id', 'name', 'version', 'is_active', 'is_demo', 'data', 'thumbnail', 'stats', 'created_by', 'updated_by'];

    protected $casts = [
        'data' => 'array', 'stats' => 'array', 'is_active' => 'boolean', 'is_demo' => 'boolean', 'version' => 'integer', 'classroom_id' => 'integer',
    ];

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ClassroomLayoutVersion::class);
    }

    public function getMorphClass(): string
    {
        return 'classroom_layout';
    }
}
