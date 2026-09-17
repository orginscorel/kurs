<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherAvailability extends Model
{
    public $timestamps = false;

    protected $fillable = ['teacher_id', 'weekday', 'starts_at', 'ends_at'];
}
