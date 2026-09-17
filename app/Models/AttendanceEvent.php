<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    use BelongsToBranch;

    public $timestamps = false;

    protected $fillable = [
        'branch_id', 'device_id', 'student_id', 'person_type', 'person_id', 'event_type', 'source',
        'occurred_at', 'received_at', 'idempotency_key', 'raw_identifier', 'is_matched', 'recorded_by',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'received_at' => 'datetime', 'is_matched' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
