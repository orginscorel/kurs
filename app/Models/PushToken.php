<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushToken extends Model
{
    protected $fillable = ['user_id', 'platform', 'token', 'device_name', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];
}
