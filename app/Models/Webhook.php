<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    use BelongsToBranch;

    public const EVENTS = [
        'student.created', 'student.updated', 'student.entry', 'student.exit', 'attendance.absent',
        'payment.received', 'payment.overdue', 'exam.completed', 'exam.result.created',
        'attendance.late', 'student.no_show_today', 'payment.voided', 'enrollment.created', 'lead.converted',
        'risk.high', 'homework.missed', 'student.class_changed',
    ];

    protected $fillable = ['branch_id', 'name', 'url', 'events', 'secret_encrypted', 'is_active'];

    protected $hidden = ['secret_encrypted'];

    protected $casts = ['events' => 'array', 'is_active' => 'boolean', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
