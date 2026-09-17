<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Öğretmen gözlem notu / davranış puanı. Varsayılan olarak yalnız personel görür;
 * öğretmen isterse veliye (visible_to_guardian) ve/veya öğrenciye (visible_to_student) açar.
 */
class StudentObservation extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const KINDS = ['positive' => 'Olumlu', 'improve' => 'Gelişmeli', 'note' => 'Not'];

    public const CATEGORIES = [
        'participation' => 'Derse katılım',
        'homework' => 'Ödev sorumluluğu',
        'behavior' => 'Davranış',
        'progress' => 'Akademik gelişim',
        'attention' => 'Dikkat / odak',
        'general' => 'Genel',
    ];

    protected $fillable = [
        'branch_id', 'student_id', 'teacher_id', 'user_id', 'class_group_id', 'subject_id',
        'kind', 'category', 'points', 'body', 'visible_to_guardian', 'visible_to_student',
    ];

    protected $casts = ['points' => 'integer', 'visible_to_guardian' => 'boolean', 'visible_to_student' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }
}
