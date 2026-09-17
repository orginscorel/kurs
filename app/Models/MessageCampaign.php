<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Toplu e-posta / SMS gönderimi. */
class MessageCampaign extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const STATUSES = [
        'draft' => 'Taslak',
        'scheduled' => 'Zamanlandı',
        'sending' => 'Gönderiliyor',
        'completed' => 'Tamamlandı',
        'cancelled' => 'İptal edildi',
    ];

    protected $fillable = [
        'branch_id', 'name', 'channels', 'is_commercial', 'audience', 'options', 'sms_body', 'email_subject', 'email_body',
        'status', 'scheduled_at', 'estimate', 'recipients_total', 'created_by', 'approved_by', 'approved_at',
        'started_at', 'completed_at', 'cancelled_at',
    ];

    protected $casts = [
        'channels' => 'array', 'audience' => 'array', 'options' => 'array', 'estimate' => 'array', 'is_commercial' => 'boolean',
        'scheduled_at' => 'datetime', 'approved_at' => 'datetime', 'started_at' => 'datetime',
        'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(MessageCampaignRecipient::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
