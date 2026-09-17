<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Optik okuma kolon düzeni şablonu (tekrar kullanılabilir eşleştirme). */
class OpticalLayout extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'name', 'format', 'mapping', 'created_by'];

    protected $casts = ['mapping' => 'array'];
}
