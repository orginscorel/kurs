<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taksit hatırlatma dedupe kaydı: aynı taksit + kural için (before_5, before_2, due,
 * after_3, after_7 …) ikinci kez mesaj üretilmesini engeller.
 */
class InstallmentReminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['installment_id', 'rule_key', 'outbound_message_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
