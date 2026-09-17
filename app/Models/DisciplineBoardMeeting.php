<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Disiplin kurulu toplantısı; gündem maddeleri discipline_board_items. */
class DisciplineBoardMeeting extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = ['branch_id', 'meeting_no', 'title', 'scheduled_at', 'location', 'status', 'members', 'notes', 'held_at', 'created_by'];

    protected $casts = ['scheduled_at' => 'datetime', 'held_at' => 'datetime', 'members' => 'array'];

    public function items(): HasMany
    {
        return $this->hasMany(DisciplineBoardItem::class, 'meeting_id')->orderBy('position')->orderBy('id');
    }
}
