<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\Sensitive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const STATUSES = [
        'lead' => 'Aday',
        'interview' => 'Görüşme',
        'offer' => 'Teklif',
        'pending' => 'Kayıt bekliyor',
        'enrolled' => 'Kayıt tamamlandı',
        'active' => 'Aktif',
        'frozen' => 'Donduruldu',
        'withdrawn' => 'Ayrıldı',
        'graduated' => 'Mezun',
    ];

    protected $fillable = [
        'branch_id', 'user_id', 'student_no', 'first_name', 'last_name', 'full_name',
        'national_id_encrypted', 'national_id_hash', 'national_id_last4', 'birth_date', 'gender',
        'school_name', 'school_grade', 'field', 'target_university', 'target_department', 'phone',
        'whatsapp_phone', 'email', 'address', 'photo_path', 'status', 'guidance_teacher_id',
        'registered_on', 'medical_notes', 'notes',
    ];

    protected $hidden = ['national_id_encrypted', 'national_id_hash'];

    protected $casts = ['birth_date' => 'date', 'registered_on' => 'date'];

    protected static function booted(): void
    {
        static::saving(function (Student $s) {
            $s->full_name = trim($s->first_name.' '.$s->last_name);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class)
            ->withPivot(['relationship', 'is_primary', 'is_financially_responsible', 'receives_notifications'])
            ->withTimestamps();
    }

    public function guidanceTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'guidance_teacher_id');
    }

    public function classGroups(): BelongsToMany
    {
        return $this->belongsToMany(ClassGroup::class)->withPivot(['id', 'joined_on', 'left_on'])->withTimestamps();
    }

    /** Şu anki sınıf(lar): çıkış tarihi olmayan üyelik. */
    public function currentClassGroups(): BelongsToMany
    {
        return $this->classGroups()->wherePivotNull('left_on');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function presences(): HasMany
    {
        return $this->hasMany(DailyPresence::class);
    }

    public function examResults(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function guidanceMeetings(): HasMany
    {
        return $this->hasMany(GuidanceMeeting::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(StudentGoal::class);
    }

    public function notesList(): HasMany
    {
        return $this->hasMany(StudentNote::class);
    }

    public function homeworkSubmissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    public function riskScore(): HasOne
    {
        return $this->hasOne(StudentRiskScore::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function deviceIdentities(): MorphMany
    {
        return $this->morphMany(DeviceIdentity::class, 'person');
    }

    public function primaryGuardian(): ?Guardian
    {
        return $this->guardians->sortByDesc(fn ($g) => (int) $g->pivot->is_primary)->first();
    }

    public function messagingPhone(): ?string
    {
        return Sensitive::normalizePhone($this->whatsapp_phone ?: $this->phone);
    }
}
