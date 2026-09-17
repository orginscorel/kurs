<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'username', 'successful', 'channel', 'ip_address', 'user_agent'];

    protected $casts = ['successful' => 'boolean', 'created_at' => 'datetime'];
}
