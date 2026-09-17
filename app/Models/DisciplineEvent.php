<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Olay zaman çizelgesi satırı (yalnız eklenir). */
class DisciplineEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['incident_id', 'user_id', 'type', 'message', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
