<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $fillable = ['branch_id', 'key', 'event_type', 'audience', 'channel', 'name', 'subject', 'body', 'provider_template', 'language', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** {{degisken}} yer tutucularını doldurur; bilinmeyen değişken boş kalır. */
    public function render(array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($vars[$m[1]] ?? ''), $this->body);
    }
}
