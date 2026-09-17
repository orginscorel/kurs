<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['kind', 'status', 'path', 'size', 'checksum', 'error', 'started_at', 'finished_at'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
}
