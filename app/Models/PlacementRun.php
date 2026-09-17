<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Toplu sınıf işlemi (otomatik yerleştirme / seviye atlatma / demo dönüşümü) + geri alma görüntüsü. */
class PlacementRun extends Model
{
    use BelongsToBranch;

    public const KINDS = ['auto' => 'Otomatik yerleştirme', 'promotion' => 'Seviye atlatma', 'restructure' => 'Sınıf yapısı dönüşümü'];

    protected $fillable = ['branch_id', 'academic_term_id', 'kind', 'grade_levels', 'summary', 'snapshot', 'applied_by', 'reverted_at', 'reverted_by'];

    protected $casts = ['grade_levels' => 'array', 'summary' => 'array', 'snapshot' => 'array', 'reverted_at' => 'datetime'];

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
