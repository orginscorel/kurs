<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Device extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'name', 'kind', 'location', 'direction', 'serial_no', 'api_token_hash', 'api_token_prefix', 'firmware', 'is_active'];

    protected $hidden = ['api_token_hash'];

    protected $casts = ['last_seen_at' => 'datetime', 'is_active' => 'boolean'];

    /** Yeni jeton üretir; düz metin yalnızca bir kez döner, veritabanında özeti kalır. */
    public function issueToken(): string
    {
        $token = 'dev_'.Str::random(48);
        $this->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'api_token_prefix' => substr($token, 0, 12),
        ])->save();

        return $token;
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }
}
