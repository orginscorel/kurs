<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bir olay bildirimi gönderiminin başlığı: taslak → onaylı → gönderildi.
 * Gerçek mesajlar `outbound_messages` (batch_id) satırlarıdır; burada durum, PDF önizleme ve sayaçlar tutulur.
 */
class NotificationBatch extends Model
{
    use BelongsToBranch;

    public const STATUSES = ['draft' => 'Taslak', 'approved' => 'Onaylandı', 'sent' => 'Gönderildi', 'cancelled' => 'İptal'];

    protected $fillable = [
        'branch_id', 'event_type', 'title', 'status', 'audiences', 'context', 'pdf_path',
        'total', 'sent', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'audiences' => 'array',
        'context' => 'array',
        'approved_at' => 'datetime',
        'total' => 'integer',
        'sent' => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(OutboundMessage::class, 'batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
