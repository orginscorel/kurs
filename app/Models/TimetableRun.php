<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Program botu çalıştırması (öneri). result/snapshot büyük JSON olduğu için longText + dizi dönüşümü. */
class TimetableRun extends Model
{
    use BelongsToBranch;

    public const STATUS_LABELS = [
        'queued' => 'Sırada', 'running' => 'Çalışıyor', 'completed' => 'Öneri hazır', 'failed' => 'Başarısız',
        'applied' => 'Uygulandı', 'discarded' => 'Vazgeçildi', 'rolled_back' => 'Geri alındı',
    ];

    protected $fillable = [
        'branch_id', 'academic_term_id', 'class_group_ids', 'settings', 'status', 'progress', 'log', 'result', 'penalty', 'quality',
        'required_count', 'placed_count', 'unplaced_count', 'hard_violations', 'duration_ms', 'error', 'apply_from', 'snapshot',
        'created_by', 'applied_by', 'started_at', 'finished_at', 'applied_at', 'rolled_back_at',
    ];

    protected $casts = [
        'class_group_ids' => 'array', 'settings' => 'array', 'log' => 'array', 'result' => 'array', 'snapshot' => 'array',
        'apply_from' => 'date', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'applied_at' => 'datetime', 'rolled_back_at' => 'datetime',
        'penalty' => 'float', 'quality' => 'integer', 'progress' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }
}
