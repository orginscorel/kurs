<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Adres bazlı ret listesi: bu adrese toplu gönderim (kampanya) yapılmaz. */
class CommunicationSuppression extends Model
{
    use BelongsToBranch;

    public const REASONS = ['unsubscribe' => 'Abonelikten çıktı', 'ret' => 'RET bildirdi', 'manual' => 'Elle eklendi', 'bounce' => 'Adres geçersiz'];

    protected $fillable = ['branch_id', 'channel', 'address', 'reason', 'source', 'recipient_type', 'recipient_id', 'recorded_by'];

    public static function normalize(string $channel, string $address): string
    {
        return $channel === 'email' ? mb_strtolower(trim($address)) : (string) \App\Support\Sensitive::normalizePhone($address);
    }
}
