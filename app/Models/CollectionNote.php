<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tahsilat takibi: ödeme sözü, görüşme notu, hatırlatma taslağı (taslak GÖNDERİLMEZ). */
class CollectionNote extends Model
{
    use BelongsToBranch;

    public const KINDS = ['promise' => 'Ödeme sözü', 'note' => 'Görüşme notu', 'reminder' => 'Hatırlatma taslağı'];

    public const STATUSES = ['open' => 'Bekliyor', 'kept' => 'Tutuldu', 'broken' => 'Tutulmadı', 'done' => 'Tamamlandı'];

    protected $fillable = ['branch_id', 'student_id', 'guardian_id', 'kind', 'promised_date', 'promised_amount', 'responsible_user_id', 'status', 'body', 'channel', 'created_by'];

    protected $casts = ['promised_date' => 'date', 'promised_amount' => 'decimal:2'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
