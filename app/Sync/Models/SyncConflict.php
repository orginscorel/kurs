<?php

namespace App\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eşitleme çakışması / mutabakat kaydı (Ayarlar › Eşitleme çakışmaları). */
class SyncConflict extends Model
{
    protected $table = 'sync_conflicts';

    protected $guarded = ['id'];

    protected $casts = ['device_at' => 'datetime', 'server_at' => 'datetime', 'resolved_at' => 'datetime'];

    public const KINDS = [
        'field' => 'Alan çakışması',
        'attendance' => 'Yoklama çakışması',
        'delete' => 'Silme / güncelleme çakışması',
        'finance' => 'Finans mutabakatı',
        'rejected' => 'Sunucu reddetti',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(SyncDevice::class, 'device_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }
}
