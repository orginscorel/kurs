<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Veli → öğretmen mesaj / görüşme talebi. Yalnız kayda düşer; WhatsApp/SMS gönderilmez.
 * Öğretmen portalda yanıtlar ya da kapatır; veli yanıtı portalda görür.
 */
class ContactRequest extends Model
{
    use BelongsToBranch;

    public const KINDS = ['message' => 'Mesaj', 'meeting' => 'Görüşme talebi'];

    public const STATUSES = ['open' => 'Yanıt bekliyor', 'answered' => 'Yanıtlandı', 'closed' => 'Kapatıldı'];

    protected $fillable = [
        'branch_id', 'student_id', 'guardian_id', 'user_id', 'teacher_id', 'kind', 'subject', 'body',
        'preferred_times', 'status', 'response', 'responded_by', 'responded_at',
    ];

    protected $casts = ['responded_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
