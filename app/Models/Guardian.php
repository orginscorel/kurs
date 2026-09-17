<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\Sensitive;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'user_id', 'first_name', 'last_name', 'national_id_encrypted', 'national_id_hash',
        'national_id_last4', 'phone', 'whatsapp_phone', 'email', 'occupation', 'address', 'notes',
    ];

    protected $hidden = ['national_id_encrypted', 'national_id_hash'];

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)
            ->withPivot(['relationship', 'is_primary', 'is_financially_responsible', 'receives_notifications'])
            ->withTimestamps();
    }

    public function consents(): MorphMany
    {
        return $this->morphMany(CommunicationConsent::class, 'consentable');
    }

    /** Mesaj gönderilecek numara: WhatsApp numarası yoksa telefon. */
    public function messagingPhone(): ?string
    {
        return Sensitive::normalizePhone($this->whatsapp_phone ?: $this->phone);
    }
}
