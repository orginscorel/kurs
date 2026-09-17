<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OutboundMessage extends Model
{
    use BelongsToBranch;

    public const STATUSES = ['queued' => 'Kuyrukta', 'sending' => 'Gönderiliyor', 'sent' => 'Gönderildi', 'delivered' => 'İletildi', 'read' => 'Okundu', 'failed' => 'Başarısız'];

    protected $fillable = [
        'branch_id', 'channel', 'to', 'recipient_type', 'recipient_id', 'student_id', 'template_key', 'subject',
        'body', 'media_path', 'status', 'attempts', 'provider', 'provider_message_id', 'error', 'dedupe_key',
        'scheduled_at', 'sent_at', 'delivered_at', 'read_at', 'created_by', 'trigger',
        'campaign_id', 'sms_parts', 'is_commercial',
    ];

    protected $casts = ['is_commercial' => 'boolean', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime'];

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}
