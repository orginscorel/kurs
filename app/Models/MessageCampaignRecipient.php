<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageCampaignRecipient extends Model
{
    use BelongsToBranch;

    public const STATUSES = [
        'pending' => 'Sırada', 'skipped' => 'Atlandı', 'sending' => 'Gönderiliyor', 'sent' => 'Gönderildi',
        'delivered' => 'İletildi', 'failed' => 'Başarısız',
    ];

    public const SKIP_REASONS = [
        'no_address' => 'Telefon / e-posta yok',
        'no_consent' => 'Ticari ileti izni yok',
        'suppressed' => 'Ret listesinde (abonelikten çıkmış)',
        'duplicate' => 'Tekrar eden adres',
        'denied' => 'Bilgilendirme iletisini reddetmiş',
        'invalid' => 'Geçersiz adres',
        'cancelled' => 'Gönderim iptal edildi',
    ];

    protected $fillable = [
        'campaign_id', 'branch_id', 'channel', 'recipient_type', 'recipient_id', 'student_id', 'group', 'name', 'to',
        'vars', 'status', 'skip_reason', 'sms_parts', 'outbound_message_id', 'error',
    ];

    protected $casts = ['vars' => 'array'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MessageCampaign::class, 'campaign_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboundMessage::class, 'outbound_message_id');
    }
}
