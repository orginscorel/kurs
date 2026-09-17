<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Yalnızca ekleme. Güncelleme ve silme uygulama katmanında yasaktır.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'user_id', 'action', 'subject_type', 'subject_id',
        'description', 'changes', 'ip_address', 'user_agent',
    ];

    protected $casts = ['changes' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Denetim kayıtları değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Denetim kayıtları silinemez.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
