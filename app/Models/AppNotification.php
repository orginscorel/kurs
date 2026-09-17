<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'title', 'body', 'action_url', 'data', 'read_at'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime', 'created_at' => 'datetime'];
}
