<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DeviceIdentity extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'person_type', 'person_id', 'kind', 'identifier', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function person(): MorphTo
    {
        return $this->morphTo();
    }
}
