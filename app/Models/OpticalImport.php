<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpticalImport extends Model
{
    protected $fillable = [
        'exam_id', 'format', 'original_name', 'path', 'mapping', 'status', 'total_rows', 'matched_rows', 'processed_rows',
        'unmatched', 'report', 'error', 'started_at', 'finished_at', 'created_by',
    ];

    protected $hidden = ['path'];

    protected $casts = ['mapping' => 'array', 'unmatched' => 'array', 'report' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
