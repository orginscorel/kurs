<?php

namespace App\Sync\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eşleştirilmiş masaüstü / mobil cihaz. Jeton: Sanctum (yetenek 'sync'). */
class SyncDevice extends Model
{
    protected $table = 'sync_devices';

    protected $guarded = ['id'];

    protected $hidden = ['public_key'];

    protected $casts = [
        'last_seen_at' => 'datetime', 'last_push_at' => 'datetime', 'last_pull_at' => 'datetime',
        'paired_at' => 'datetime', 'revoked_at' => 'datetime', 'key_issued_at' => 'datetime',
    ];

    public const PLATFORMS = ['windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'android' => 'Android', 'ios' => 'iOS'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
