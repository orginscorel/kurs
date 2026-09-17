<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** iCal abonelik bağlantısı. Jetonun kendisi saklanmaz; yalnız SHA-256 özeti. */
class CalendarFeed extends Model
{
    use BelongsToBranch;

    public const OWNER_TYPES = ['teacher', 'student', 'class_group', 'classroom'];

    protected $fillable = ['branch_id', 'owner_type', 'owner_id', 'token_hash', 'created_by', 'last_accessed_at', 'access_count', 'revoked_at'];

    protected $casts = ['last_accessed_at' => 'datetime', 'revoked_at' => 'datetime', 'access_count' => 'integer'];
}
