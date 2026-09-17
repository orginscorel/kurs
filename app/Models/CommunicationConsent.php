<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommunicationConsent extends Model
{
    public $timestamps = false;

    protected $fillable = ['consentable_type', 'consentable_id', 'channel', 'purpose', 'granted', 'source', 'recorded_at', 'recorded_by'];

    protected $casts = ['granted' => 'boolean', 'recorded_at' => 'datetime'];

    public function consentable(): MorphTo
    {
        return $this->morphTo();
    }
}
