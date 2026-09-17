<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Integration extends Model
{
    protected $fillable = ['branch_id', 'kind', 'provider', 'config_encrypted', 'status', 'last_error', 'last_checked_at', 'is_enabled'];

    protected $hidden = ['config_encrypted'];

    protected $casts = ['last_checked_at' => 'datetime', 'is_enabled' => 'boolean'];

    public function config(): array
    {
        if (! $this->config_encrypted) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($this->config_encrypted), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setConfig(array $config): void
    {
        $this->config_encrypted = Crypt::encryptString(json_encode($config));
    }
}
