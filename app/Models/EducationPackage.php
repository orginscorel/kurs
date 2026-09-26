<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EducationPackage extends Model
{
    use BelongsToBranch, SoftDeletes;

    /** Paket türleri: ders (sınıf/program gerektirir) · kütüphane · etüt (ikisi de sınıf gerektirmez). */
    public const TYPES = [
        'course' => 'Ders paketi',
        'library' => 'Kütüphane',
        'study' => 'Etüt',
    ];

    protected $fillable = ['branch_id', 'program_id', 'academic_term_id', 'type', 'name', 'list_price', 'default_installments', 'includes', 'has_coaching', 'is_active'];

    protected $casts = ['list_price' => 'decimal:2', 'has_coaching' => 'boolean', 'is_active' => 'boolean'];

    /** Ders paketi sınıfa yerleştirme gerektirir; kütüphane/etüt gerektirmez (mezun da alabilir). */
    public function requiresClass(): bool
    {
        return ($this->type ?? 'course') === 'course';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
