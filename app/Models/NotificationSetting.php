<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Olay bazlı bildirim ayarı: aç/kapa, onay gerekliliği, kanal ve varsayılan kitleler. */
class NotificationSetting extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'event_type', 'enabled', 'require_approval', 'channels', 'audiences'];

    protected $casts = [
        'enabled' => 'boolean',
        'require_approval' => 'boolean',
        'channels' => 'array',
        'audiences' => 'array',
    ];
}
