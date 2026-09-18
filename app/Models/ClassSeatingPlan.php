<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Sınıfın bir oda düzenindeki oturma planı (masa kimliği → öğrenci UUID'leri, masa durumları). */
class ClassSeatingPlan extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'class_group_id', 'classroom_layout_id', 'layout_version', 'seats', 'statuses', 'updated_by'];

    protected $casts = ['seats' => 'array', 'statuses' => 'array', 'layout_version' => 'integer', 'class_group_id' => 'integer', 'classroom_layout_id' => 'integer'];

    public function getMorphClass(): string
    {
        return 'class_seating_plan';
    }
}
