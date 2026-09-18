<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Derslik tasarımının kaydedilmiş sürümü (V1, V2…). Yalnız eklenir; geri yükleme yeni sürüm üretir. */
class ClassroomLayoutVersion extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'classroom_layout_id', 'version', 'label', 'data', 'stats', 'created_by'];

    protected $casts = ['data' => 'array', 'stats' => 'array', 'version' => 'integer'];

    public function layout(): BelongsTo
    {
        return $this->belongsTo(ClassroomLayout::class, 'classroom_layout_id');
    }
}
