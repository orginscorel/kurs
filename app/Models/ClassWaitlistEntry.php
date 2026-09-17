<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sınıf bekleme listesi satırı. */
class ClassWaitlistEntry extends Model
{
    use BelongsToBranch;

    public const SOURCES = ['placement' => 'Otomatik yerleştirme', 'change' => 'Şube değişim isteği', 'restructure' => 'Sınıf yapısı dönüşümü', 'manual' => 'Elle eklendi', 'promotion' => 'Seviye atlatma'];

    protected $table = 'class_waitlist';

    protected $fillable = ['branch_id', 'academic_term_id', 'student_id', 'grade_level', 'preferred_section', 'source', 'reason', 'status', 'placement_run_id', 'created_by', 'resolved_at'];

    protected $casts = ['grade_level' => 'integer', 'resolved_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
