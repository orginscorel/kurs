<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'entity', 'path', 'original_name', 'mapping', 'status', 'total_rows', 'success_rows', 'errors', 'created_by'];

    protected $hidden = ['path'];

    protected $casts = ['mapping' => 'array', 'errors' => 'array', 'created_by' => 'integer'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
