<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_id', 'event', 'payload', 'response_status', 'response_body', 'attempts', 'status', 'next_attempt_at'];

    protected $casts = ['payload' => 'array', 'next_attempt_at' => 'datetime'];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
