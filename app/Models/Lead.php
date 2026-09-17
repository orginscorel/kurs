<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const STAGES = [
        'new' => 'Yeni Aday',
        'called' => 'Arandı',
        'meeting_scheduled' => 'Görüşme Planlandı',
        'met' => 'Görüşüldü',
        'offered' => 'Teklif Verildi',
        'undecided' => 'Kararsız',
        'call_again' => 'Tekrar Aranacak',
        'won' => 'Kayıt Oldu',
        'lost' => 'Kaybedildi',
    ];

    public const SOURCES = [
        'instagram' => 'Instagram', 'google' => 'Google', 'facebook' => 'Facebook', 'whatsapp' => 'WhatsApp',
        'website' => 'Web sitesi', 'phone' => 'Telefon', 'referral' => 'Referans',
        'student_referral' => 'Öğrenci yönlendirmesi', 'walk_in' => 'Kuruma gelen', 'other' => 'Diğer',
    ];

    protected $fillable = [
        'branch_id', 'first_name', 'last_name', 'phone', 'guardian_name', 'guardian_phone', 'email',
        'school_name', 'school_grade', 'interested_program_id', 'source', 'source_detail', 'stage',
        'lost_reason', 'offered_price', 'owner_id', 'last_contacted_at', 'next_action_at', 'next_action',
        'student_id', 'stage_position',
    ];

    protected $casts = ['converted_at' => 'datetime', 'last_contacted_at' => 'datetime', 'next_action_at' => 'datetime', 'offered_price' => 'decimal:2'];

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'interested_program_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('created_at');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }
}
