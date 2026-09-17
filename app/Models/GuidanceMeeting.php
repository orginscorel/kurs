<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuidanceMeeting extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const KINDS = ['individual' => 'Bireysel', 'guardian' => 'Veli', 'group' => 'Grup', 'phone' => 'Telefon', 'online' => 'Çevrim içi'];

    protected $fillable = [
        'branch_id', 'student_id', 'counselor_id', 'met_at', 'kind', 'summary', 'goal', 'motivation',
        'study_discipline', 'private_note', 'visibility', 'visible_to_student', 'next_meeting_on',
    ];

    protected $hidden = ['private_note'];

    protected $casts = ['met_at' => 'datetime', 'next_meeting_on' => 'date', 'visible_to_student' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }
}
