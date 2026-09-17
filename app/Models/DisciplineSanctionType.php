<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Yaptırım kademesi: kim verir (staff = discipline.decide yetkilisi, board = kurul), süreli mi, uzaklaştırma mı. */
class DisciplineSanctionType extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'code', 'name', 'level', 'authority', 'is_suspension', 'has_duty', 'expires_after_days', 'tone', 'description', 'is_active'];

    protected $casts = ['level' => 'integer', 'is_suspension' => 'boolean', 'has_duty' => 'boolean', 'expires_after_days' => 'integer', 'is_active' => 'boolean'];

    public function requiresBoard(): bool
    {
        return $this->authority === 'board';
    }
}
