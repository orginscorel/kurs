<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use BelongsToBranch;

    public const KINDS = ['official' => 'Resmi tatil', 'institution' => 'Kurum tatili'];

    protected $fillable = ['branch_id', 'name', 'kind', 'starts_on', 'ends_on', 'cancel_sessions', 'cancelled_count', 'notes', 'created_by'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'cancel_sessions' => 'boolean', 'cancelled_count' => 'integer'];
}
